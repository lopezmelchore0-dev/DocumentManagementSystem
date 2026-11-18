<?php
    /*
     * Per-device sign-in tokens.
     *
     * Every successful login gets its own row in `account_sessions`, so the same account can stay
     * signed in on a laptop, a phone and other browsers at the same time. session.php checks the
     * token stored in $_SESSION against this table; logout.php removes only that device's row;
     * approving a password reset removes every row for the account (signs it out everywhere).
     *
     * The old single `accounts.token` column is no longer used for authentication.
     */

    if (!function_exists('dms_auth_ensure_schema')) {
        /** $force re-checks even if already done this request (used after a database restore). */
        function dms_auth_ensure_schema($conn, $force = false)
        {
            static $done = false;
            if ($done && !$force) {
                return;
            }
            $done = true;

            try {
                $res = $conn->query("SHOW TABLES LIKE 'account_sessions'");
                if ($res && $res->num_rows === 0) {
                    $conn->query("CREATE TABLE IF NOT EXISTS `account_sessions` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `account_id` int(11) NOT NULL,
                        `token` char(64) NOT NULL,
                        `user_agent` varchar(255) NOT NULL DEFAULT '',
                        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `last_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uq_token` (`token`),
                        KEY `idx_account` (`account_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

                    // Keep anyone already signed in (legacy single token) signed in after the upgrade.
                    $conn->query("INSERT IGNORE INTO `account_sessions` (account_id, token)
                                  SELECT id, token FROM `accounts`
                                  WHERE token IS NOT NULL AND CHAR_LENGTH(token) = 64");
                }
            } catch (Throwable $e) {
                // Leave the page working; database/add_account_sessions.sql can be run manually.
            }
        }

        /** Creates a new sign-in token for this device and returns it. */
        function dms_auth_create($conn, $accountId)
        {
            dms_auth_ensure_schema($conn);

            $accountId = (int) $accountId;
            $token = bin2hex(random_bytes(32));
            $agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

            $stmt = $conn->prepare("INSERT INTO account_sessions (account_id, token, user_agent) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $accountId, $token, $agent);
            $stmt->execute();

            // Housekeeping: forget devices that have not been used for 30 days.
            $old = $conn->prepare("DELETE FROM account_sessions
                                   WHERE account_id = ? AND last_seen < (NOW() - INTERVAL 30 DAY)");
            $old->bind_param("i", $accountId);
            $old->execute();

            return $token;
        }

        /** True when $token is a live sign-in for the account with $email. */
        function dms_auth_valid($conn, $email, $token)
        {
            dms_auth_ensure_schema($conn);

            $email = (string) $email;
            $token = (string) $token;
            if ($email === '' || $token === '') {
                return false;
            }

            $stmt = $conn->prepare("SELECT s.id, (s.last_seen < (NOW() - INTERVAL 5 MINUTE)) AS stale
                                    FROM account_sessions s
                                    JOIN accounts a ON a.id = s.account_id
                                    WHERE a.email = ? AND s.token = ?
                                    LIMIT 1");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("ss", $email, $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                return false;
            }

            if ((int) $row['stale'] === 1) {
                $touch = $conn->prepare("UPDATE account_sessions SET last_seen = NOW() WHERE id = ?");
                $sid = (int) $row['id'];
                $touch->bind_param("i", $sid);
                $touch->execute();
            }
            return true;
        }

        /** Signs out one device. */
        function dms_auth_revoke($conn, $token)
        {
            dms_auth_ensure_schema($conn);

            $token = (string) $token;
            if ($token === '') {
                return;
            }
            $stmt = $conn->prepare("DELETE FROM account_sessions WHERE token = ?");
            $stmt->bind_param("s", $token);
            $stmt->execute();
        }

        /** Signs the account out of every device. */
        function dms_auth_revoke_all($conn, $accountId)
        {
            dms_auth_ensure_schema($conn);

            $accountId = (int) $accountId;
            $stmt = $conn->prepare("DELETE FROM account_sessions WHERE account_id = ?");
            $stmt->bind_param("i", $accountId);
            $stmt->execute();
        }
    }
?>
