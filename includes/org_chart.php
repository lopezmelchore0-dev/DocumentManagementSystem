<?php
/**
 * Organizational Chart (files-officials.php, the dashboard mini chart, org_chart_action.php).
 *
 * The chart is a list of groups ("Presiding Officer", "Sangguniang Bayan Members", ...). Each group
 * has an editable title and colour and holds officials in their own order. The chart title and
 * subtitle live in org_chart_meta. dms_org_ensure_schema() creates all of this the first time a
 * chart page is opened (database/add_org_chart.sql is the same thing for importing by hand).
 */

require_once __DIR__ . '/upload_helper.php'; // dms_attachment_exists(), dms_store_upload()

/** The four built-in positions and how many of each a term may have (addOfficials.php). */
function dms_org_position_limits()
{
    return ['Vice-Mayor' => 1, 'Councilor' => 10, 'LNB' => 5, 'PPSK' => 4];
}

/** Colours offered for groups (the first three are used for the seeded groups). */
function dms_org_palette()
{
    return ['#098209', '#0F8C83', '#E07B00', '#C2185B', '#3B5BDB', '#7A4FC2', '#5C6B73'];
}

function dms_org_default_meta()
{
    return [
        'title'    => 'Sangguniang Bayan Organizational Chart',
        'subtitle' => '',
    ];
}

/** Creates the tables/columns on first use and seeds the three default groups. */
function dms_org_ensure_schema($conn)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `org_chart_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(150) NOT NULL DEFAULT '',
            `color` varchar(7) NOT NULL DEFAULT '#098209',
            `sort_order` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS `org_chart_meta` (
            `k` varchar(50) NOT NULL,
            `v` varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $res = $conn->query("SHOW COLUMNS FROM `officials` LIKE 'group_id'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `officials`
                ADD COLUMN `group_id` int(11) DEFAULT NULL,
                ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0");
        }

        $res = $conn->query("SELECT COUNT(*) AS c FROM `org_chart_groups`");
        if ($res && (int) $res->fetch_assoc()['c'] === 0) {
            $pal = dms_org_palette();
            $seed = [
                ['Presiding Officer',         $pal[0], ['Vice-Mayor']],
                ['Sangguniang Bayan Members', $pal[1], ['Councilor']],
                ['Ex-Officio Members',        $pal[2], ['LNB', 'PPSK']],
            ];
            $ins = $conn->prepare("INSERT INTO `org_chart_groups` (`title`, `color`, `sort_order`) VALUES (?, ?, ?)");
            $upd = $conn->prepare("UPDATE `officials` SET `group_id` = ? WHERE `group_id` IS NULL AND `position` = ?");
            $lastId = 0;
            foreach ($seed as $i => $g) {
                $order = $i + 1;
                $ins->bind_param('ssi', $g[0], $g[1], $order);
                $ins->execute();
                $lastId = (int) $conn->insert_id;
                foreach ($g[2] as $pos) {
                    $upd->bind_param('is', $lastId, $pos);
                    $upd->execute();
                }
            }
            // Any other position goes to the last group.
            $conn->query("UPDATE `officials` SET `group_id` = " . (int) $lastId . " WHERE `group_id` IS NULL");
        }
    } catch (Throwable $e) {
        // Leave the page usable; dms_org_load() copes with missing tables.
    }
}

/** Chart title/subtitle. */
function dms_org_meta($conn)
{
    $meta = dms_org_default_meta();
    try {
        $res = $conn->query("SELECT `k`, `v` FROM `org_chart_meta`");
        while ($res && ($r = $res->fetch_assoc())) {
            if (array_key_exists($r['k'], $meta)) {
                $meta[$r['k']] = $r['v'];
            }
        }
    } catch (Throwable $e) {
    }
    return $meta;
}

/** Groups in display order: [['id','title','color','sort_order'], ...]. */
function dms_org_groups($conn)
{
    $groups = [];
    try {
        $res = $conn->query("SELECT `id`, `title`, `color`, `sort_order` FROM `org_chart_groups` ORDER BY `sort_order`, `id`");
        while ($res && ($r = $res->fetch_assoc())) {
            $r['id'] = (int) $r['id'];
            $groups[] = $r;
        }
    } catch (Throwable $e) {
    }
    return $groups;
}

/**
 * The group a new official with this position belongs in: the group that already holds someone
 * with the same position, otherwise the last group. Returns null when there are no groups.
 */
function dms_org_default_group($conn, $position)
{
    try {
        $stmt = $conn->prepare("SELECT o.`group_id` FROM `officials` o
                                JOIN `org_chart_groups` g ON g.`id` = o.`group_id`
                                WHERE o.`position` = ? GROUP BY o.`group_id` ORDER BY COUNT(*) DESC LIMIT 1");
        $stmt->bind_param('s', $position);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return (int) $row['group_id'];
        }
    } catch (Throwable $e) {
    }
    $groups = dms_org_groups($conn);
    return $groups ? (int) end($groups)['id'] : null;
}

/** True when $groupId is an existing group. */
function dms_org_group_exists($conn, $groupId)
{
    $stmt = $conn->prepare("SELECT 1 FROM `org_chart_groups` WHERE `id` = ?");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row();
}

/** Positions already in use (built-in ones first), for the position pickers. */
function dms_org_known_positions($conn)
{
    $list = array_keys(dms_org_position_limits());
    try {
        $res = $conn->query("SELECT DISTINCT `position` FROM `officials` WHERE `position` <> '' ORDER BY `position`");
        while ($res && ($r = $res->fetch_assoc())) {
            if (!in_array($r['position'], $list, true)) {
                $list[] = $r['position'];
            }
        }
    } catch (Throwable $e) {
    }
    return $list;
}

/** The most recent term in officials, for pre-filling new officials: ['start' => ..., 'end' => ...]. */
function dms_org_latest_term($conn)
{
    try {
        $res = $conn->query("SELECT `term_start`, `term_end` FROM `officials`
                             WHERE `term_start` IS NOT NULL ORDER BY `term_start` DESC, `term_end` DESC LIMIT 1");
        if ($res && ($r = $res->fetch_assoc())) {
            return ['start' => (string) $r['term_start'], 'end' => (string) $r['term_end']];
        }
    } catch (Throwable $e) {
    }
    return ['start' => '', 'end' => ''];
}

/** Photo URL for an official, or null when there is none / the file is missing. */
function dms_org_photo($row)
{
    $path = trim((string) ($row['photo_path'] ?? ''));
    return ($path !== '' && dms_attachment_exists($path)) ? $path : null;
}

/** "Juan D. Dela Cruz" */
function dms_org_display_name($row)
{
    $first = trim((string) ($row['firstname'] ?? ''));
    $mid = trim((string) ($row['middlename'] ?? ''));
    $sur = trim((string) ($row['surname'] ?? ''));
    $mi = $mid !== '' ? mb_strtoupper(mb_substr($mid, 0, 1)) . '.' : '';
    return trim(preg_replace('/\s+/', ' ', "$first $mi $sur"));
}

function dms_org_initials($row)
{
    $f = trim((string) ($row['firstname'] ?? ''));
    $s = trim((string) ($row['surname'] ?? ''));
    $out = ($f !== '' ? mb_substr($f, 0, 1) : '') . ($s !== '' ? mb_substr($s, 0, 1) : '');
    return $out !== '' ? mb_strtoupper($out) : '?';
}

/**
 * Everything the chart needs:
 * ['title', 'subtitle', 'count', 'groups' => [['id','title','color','members' => [official rows]]]]
 */
function dms_org_load($conn)
{
    $meta = dms_org_meta($conn);
    $groups = dms_org_groups($conn);
    $byId = [];
    foreach ($groups as $i => $g) {
        $groups[$i]['members'] = [];
        $byId[$g['id']] = $i;
    }

    $rows = [];
    try {
        $res = $conn->query("SELECT * FROM `officials`
            ORDER BY `sort_order`,
                     FIELD(`position`, 'Vice-Mayor', 'Councilor', 'LNB', 'PPSK') = 0,
                     FIELD(`position`, 'Vice-Mayor', 'Councilor', 'LNB', 'PPSK'),
                     `surname`, `firstname`, `id`");
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
    } catch (Throwable $e) {
    }

    if (!$groups && $rows) {
        // Tables could not be created: show everyone in one unnamed group so nobody disappears.
        $groups[] = ['id' => 0, 'title' => 'Officials', 'color' => dms_org_palette()[0], 'sort_order' => 0, 'members' => []];
        $byId[0] = 0;
    }

    // Officials without a (valid) group: put them where others with the same position are,
    // otherwise in the last group.
    $posGroup = [];
    foreach ($rows as $r) {
        if (isset($r['group_id']) && isset($byId[(int) $r['group_id']]) && !isset($posGroup[$r['position']])) {
            $posGroup[$r['position']] = (int) $r['group_id'];
        }
    }
    foreach ($rows as $r) {
        $gid = isset($r['group_id']) ? (int) $r['group_id'] : -1;
        if (!isset($byId[$gid])) {
            $gid = $posGroup[$r['position']] ?? ($groups ? end($groups)['id'] : 0);
        }
        if (isset($byId[$gid])) {
            $groups[$byId[$gid]]['members'][] = $r;
        }
    }

    return [
        'title'    => $meta['title'],
        'subtitle' => $meta['subtitle'],
        'count'    => count($rows),
        'groups'   => $groups,
    ];
}

/**
 * Chart markup. $opts: 'mini' (dashboard card), 'editable' (master/admin edit controls),
 * 'link' (mini: where clicking an official goes).
 */
function dms_org_render($data, array $opts = [])
{
    $mini = !empty($opts['mini']);
    $editable = !$mini && !empty($opts['editable']);
    $link = $opts['link'] ?? 'files-officials.php';
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

    ob_start();
    ?>
    <div class="dms-org<?php echo $mini ? ' is-mini' : ''; ?>"<?php echo $mini ? '' : ' id="orgChart"'; ?><?php echo $editable ? ' data-org-editable' : ''; ?>>
        <?php if (!$mini) { ?>
            <div class="dms-org-head">
                <h2 class="dms-org-title" data-org-meta="title" data-placeholder="Chart title"><?php echo $e($data['title']); ?></h2>
                <p class="dms-org-sub<?php echo trim($data['subtitle']) === '' ? ' is-empty' : ''; ?>" data-org-meta="subtitle" data-placeholder="Add a subtitle (e.g. municipality and term)"><?php echo $e($data['subtitle']); ?></p>
            </div>
        <?php } ?>

        <?php foreach ($data['groups'] as $gi => $g) {
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $g['color']) ? $g['color'] : dms_org_palette()[0];
            if ($mini && !$g['members']) { continue; }
            ?>
            <section class="dms-org-group" data-group-id="<?php echo (int) $g['id']; ?>" style="--org-color: <?php echo $e($color); ?>;">
                <?php if ($editable) { ?>
                    <div class="dms-org-gtools dms-org-tool" role="group" aria-label="Group tools">
                        <label class="dms-org-swatch" title="Group colour">
                            <input type="color" value="<?php echo $e(strtolower($color)); ?>" data-org-color aria-label="Group colour">
                        </label>
                        <button type="button" class="dms-org-tbtn" data-org-gmove="up" title="Move group up"<?php echo $gi === 0 ? ' disabled' : ''; ?>><i class="ti-arrow-up"></i></button>
                        <button type="button" class="dms-org-tbtn" data-org-gmove="down" title="Move group down"<?php echo $gi === count($data['groups']) - 1 ? ' disabled' : ''; ?>><i class="ti-arrow-down"></i></button>
                        <button type="button" class="dms-org-tbtn is-danger" data-org-gdelete title="Delete group"<?php echo count($data['groups']) < 2 ? ' disabled' : ''; ?>><i class="ti-trash"></i></button>
                    </div>
                <?php } ?>
                <h3 class="dms-org-gtitle" data-org-gtitle data-placeholder="Group title"><?php echo $e($g['title']); ?></h3>
                <ul class="dms-org-members" data-org-members>
                    <?php
                    $n = count($g['members']);
                    foreach ($g['members'] as $mi => $m) {
                        $photo = dms_org_photo($m);
                        $name = dms_org_display_name($m);
                        $cls = 'dms-org-member';
                        if ($n === 1) { $cls .= ' is-solo'; }
                        if ($mini) {
                            $label = trim((string) $m['surname']) !== '' ? $m['surname'] : $name;
                            ?>
                            <li class="<?php echo $cls; ?>">
                                <a class="dms-org-card" href="<?php echo $e($link); ?>" title="<?php echo $e($name . ' — ' . $m['position']); ?>">
                                    <span class="dms-org-photo">
                                        <?php if ($photo) { ?>
                                            <span class="dms-org-img" role="img" aria-label="<?php echo $e($name); ?>" style="background-image: url('<?php echo $e(str_replace("'", '%27', $photo)); ?>');"></span>
                                        <?php } else { ?>
                                            <span class="dms-org-initials" aria-hidden="true"><?php echo $e(dms_org_initials($m)); ?></span>
                                        <?php } ?>
                                    </span>
                                    <span class="dms-org-name"><?php echo $e($label); ?></span>
                                </a>
                            </li>
                            <?php
                            continue;
                        }
                        ?>
                        <li class="<?php echo $cls; ?>" data-official-id="<?php echo (int) $m['id']; ?>"
                            data-official="<?php echo $e(json_encode($m)); ?>">
                            <div class="dms-org-card" tabindex="0" role="button" aria-label="<?php echo $e($name . ', ' . $m['position'] . ' — show details'); ?>">
                                <span class="dms-org-photo">
                                    <?php if ($photo) { ?>
                                        <span class="dms-org-img" role="img" aria-label="<?php echo $e($name); ?>" style="background-image: url('<?php echo $e(str_replace("'", '%27', $photo)); ?>');"></span>
                                    <?php } else { ?>
                                        <span class="dms-org-initials" aria-hidden="true"><?php echo $e(dms_org_initials($m)); ?></span>
                                    <?php } ?>
                                    <?php if ($editable) { ?>
                                        <button type="button" class="dms-org-cam dms-org-tool" data-org-photo title="Change photo" aria-label="Change photo of <?php echo $e($name); ?>"><i class="ti-camera"></i></button>
                                    <?php } ?>
                                </span>
                                <span class="dms-org-name"><?php echo $e($name !== '' ? $name : 'Unnamed'); ?></span>
                                <span class="dms-org-pos"><?php echo $e($m['position']); ?></span>
                            </div>
                            <?php if ($editable) { ?>
                                <div class="dms-org-mtools dms-org-tool" role="group" aria-label="Official tools">
                                    <button type="button" class="dms-org-tbtn" data-org-mmove="-1" title="Move left"><i class="ti-angle-left"></i></button>
                                    <button type="button" class="dms-org-tbtn" data-org-medit title="Edit details"><i class="ti-pencil"></i></button>
                                    <button type="button" class="dms-org-tbtn" data-org-mmove="1" title="Move right"><i class="ti-angle-right"></i></button>
                                </div>
                            <?php } ?>
                        </li>
                    <?php } ?>
                    <?php if ($editable) { ?>
                        <li class="dms-org-add dms-org-tool">
                            <button type="button" class="dms-org-addbtn" data-org-quickadd title="Add an official to this group">
                                <span class="dms-org-photo"><i class="ti-plus"></i></span>
                                <span class="dms-org-name">Add official</span>
                            </button>
                        </li>
                    <?php } ?>
                </ul>
                <?php if (!$g['members'] && !$editable && !$mini) { ?>
                    <p class="dms-org-none">No officials in this group yet.</p>
                <?php } ?>
            </section>
        <?php } ?>

        <?php if ($editable) { ?>
            <div class="dms-org-addgroup dms-org-tool">
                <button type="button" class="btn btn-light" data-org-gadd><i class="ti-plus"></i>&nbsp; Add group</button>
            </div>
        <?php } ?>
    </div>
    <?php
    return ob_get_clean();
}
