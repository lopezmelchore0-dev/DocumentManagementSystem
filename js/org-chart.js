/*
 * Organizational Chart (includes/org_chart.php markup).
 *  - every .dms-org: bracket connector ends are recalculated per wrapped row
 *  - #orgChart: Download PNG / Copy image ([data-org-download], [data-org-copy])
 *  - #orgChart[data-org-editable]: edit mode ([data-org-toggle]) — inline titles, group colours,
 *    group add/move/delete, photo change, drag (or arrow) re-ordering, quick add of officials.
 *    Changes are saved through org_chart_action.php.
 */
(function () {
    "use strict";

    var ACTION_URL = "org_chart_action.php";
    var EDIT_KEY = "dms.org.editing";
    var H2C_URL = "https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js";

    /* ---------- connector lines: mark the first/last item of every wrapped row ---------- */
    function lines(scope) {
        (scope || document).querySelectorAll(".dms-org-members").forEach(function (list) {
            var items = Array.prototype.filter.call(list.children, function (li) {
                li.classList.remove("row-first", "row-last", "row-solo");
                return li.offsetParent !== null;
            });
            var rows = [];
            items.forEach(function (li) {
                var top = li.offsetTop;
                var row = rows.length ? rows[rows.length - 1] : null;
                if (!row || Math.abs(row.top - top) > 4) {
                    rows.push(row = { top: top, items: [] });
                }
                row.items.push(li);
            });
            rows.forEach(function (row) {
                row.items[0].classList.add("row-first");
                row.items[row.items.length - 1].classList.add("row-last");
                if (row.items.length === 1) { row.items[0].classList.add("row-solo"); }
            });
            list.classList.add("is-measured");
        });
    }

    var resizeTimer = null;
    window.addEventListener("resize", function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () { lines(); }, 120);
    });

    /* ---------- helpers ---------- */
    function swalOk() { return typeof window.Swal !== "undefined"; }

    function toast(message, icon) {
        if (!swalOk()) { return; }
        Swal.fire({ toast: true, position: "top-end", icon: icon || "success", title: message, showConfirmButton: false, timer: 1600 });
    }

    function fail(message) {
        if (swalOk()) {
            Swal.fire({ icon: "error", title: "Not saved", text: message || "The change could not be saved.", confirmButtonText: "OK" });
        } else {
            alert(message || "The change could not be saved.");
        }
    }

    function post(data) {
        var fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        }
        return fetch(ACTION_URL, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (res) {
                return res.json().catch(function () { return { success: false, message: "Unexpected server response (" + res.status + ")." }; });
            })
            .then(function (json) {
                if (!json || !json.success) { throw new Error((json && json.message) || "The change could not be saved."); }
                return json;
            });
    }

    function reloadKeepingEdit() {
        try { sessionStorage.setItem(EDIT_KEY, "1"); } catch (e) { /* storage unavailable */ }
        location.reload();
    }

    /* ---------- export: PNG download / copy to clipboard ---------- */
    var h2cPromise = null;
    function loadHtml2Canvas() {
        if (window.html2canvas) { return Promise.resolve(window.html2canvas); }
        if (!h2cPromise) {
            h2cPromise = new Promise(function (resolve, reject) {
                var s = document.createElement("script");
                s.src = H2C_URL;
                s.onload = function () { resolve(window.html2canvas); };
                s.onerror = function () { h2cPromise = null; reject(new Error("The image library could not be loaded. Check the internet connection.")); };
                document.head.appendChild(s);
            });
        }
        return h2cPromise;
    }

    function renderBlob(root) {
        return loadHtml2Canvas().then(function (h2c) {
            var wasEditing = root.classList.contains("is-editing");
            root.classList.remove("is-editing");
            root.classList.add("is-exporting");
            if (document.activeElement && root.contains(document.activeElement)) { document.activeElement.blur(); }
            lines(root);
            return h2c(root, { backgroundColor: "#ffffff", scale: 2, useCORS: true, logging: false })
                .finally(function () {
                    root.classList.remove("is-exporting");
                    if (wasEditing) { root.classList.add("is-editing"); }
                    lines(root);
                })
                .then(function (canvas) {
                    return new Promise(function (resolve, reject) {
                        canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error("The image could not be created.")); }, "image/png");
                    });
                });
        });
    }

    function fileName(root) {
        var t = root.querySelector(".dms-org-title");
        var base = (t && t.textContent.trim()) || "Organizational Chart";
        return base.replace(/[\\/:*?"<>|]+/g, "").replace(/\s+/g, "-") + ".png";
    }

    function downloadBlob(blob, name) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement("a");
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
    }

    function busy(btn, on) {
        if (!btn) { return; }
        btn.disabled = on;
        btn.classList.toggle("is-busy", on);
    }

    function initExport(root) {
        var dl = document.querySelector("[data-org-download]");
        var cp = document.querySelector("[data-org-copy]");

        if (dl) {
            dl.addEventListener("click", function () {
                busy(dl, true);
                renderBlob(root)
                    .then(function (blob) { downloadBlob(blob, fileName(root)); toast("Chart image downloaded"); })
                    .catch(function (e) { fail(e.message); })
                    .finally(function () { busy(dl, false); });
            });
        }

        if (cp) {
            var canCopy = window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.write === "function" && typeof window.ClipboardItem === "function";
            cp.addEventListener("click", function () {
                busy(cp, true);
                var blobPromise = renderBlob(root);
                var copied = canCopy
                    ? navigator.clipboard.write([new ClipboardItem({ "image/png": blobPromise })])
                    : Promise.reject(new Error("no-clipboard"));
                copied
                    .then(function () {
                        if (swalOk()) {
                            Swal.fire({ icon: "success", title: "Chart copied", text: "Paste it (Ctrl+V) into Word, a report or a message.", timer: 2600, showConfirmButton: false });
                        }
                    })
                    .catch(function () {
                        // Clipboard images need a secure page (https or localhost) and a recent browser.
                        return blobPromise.then(function (blob) {
                            downloadBlob(blob, fileName(root));
                            if (swalOk()) {
                                Swal.fire({ icon: "info", title: "Downloaded instead", text: "This browser could not copy the image, so it was downloaded. Insert the PNG file into your document." });
                            }
                        });
                    })
                    .catch(function (e) { fail(e.message); })
                    .finally(function () { busy(cp, false); });
            });
        }
    }

    /* ---------- details modal (existing openModal() on files-officials.php) ---------- */
    function officialData(li) {
        try { return JSON.parse(li.getAttribute("data-official") || "{}"); } catch (e) { return {}; }
    }

    function initDetails(root) {
        root.addEventListener("click", function (e) {
            if (e.target.closest(".dms-org-tool")) { return; }
            var card = e.target.closest(".dms-org-member .dms-org-card");
            if (!card || typeof window.openModal !== "function") { return; }
            if (root.classList.contains("is-editing") && justDragged) { return; }
            window.openModal(officialData(card.closest(".dms-org-member")));
        });
        root.addEventListener("keydown", function (e) {
            if ((e.key === "Enter" || e.key === " ") && e.target.matches(".dms-org-member .dms-org-card")) {
                e.preventDefault();
                e.target.click();
            }
        });
    }

    /* ---------- edit mode ---------- */
    var justDragged = false;

    function initEditing(root) {
        var toggle = document.querySelector("[data-org-toggle]");
        var editables = function () { return root.querySelectorAll("[data-org-meta], [data-org-gtitle]"); };

        function setEditing(on) {
            root.classList.toggle("is-editing", on);
            editables().forEach(function (el) {
                if (on) {
                    el.setAttribute("contenteditable", "true");
                    el.setAttribute("spellcheck", "true");
                    el.dataset.orig = el.textContent.trim();
                } else {
                    el.removeAttribute("contenteditable");
                }
            });
            root.querySelectorAll(".dms-org-member").forEach(function (li) { li.draggable = on; });
            if (toggle) {
                toggle.classList.toggle("is-active", on);
                toggle.setAttribute("aria-pressed", on ? "true" : "false");
                toggle.innerHTML = on ? '<i class="ti-check"></i>&nbsp; Done editing' : '<i class="ti-pencil-alt"></i>&nbsp; Edit chart';
            }
            try { on ? sessionStorage.setItem(EDIT_KEY, "1") : sessionStorage.removeItem(EDIT_KEY); } catch (e) { /* storage unavailable */ }
            lines(root);
        }

        if (toggle) {
            toggle.addEventListener("click", function () { setEditing(!root.classList.contains("is-editing")); });
        }
        var resume = false;
        try { resume = sessionStorage.getItem(EDIT_KEY) === "1"; } catch (e) { /* storage unavailable */ }
        if (resume) { setEditing(true); }

        /* inline text: chart title/subtitle and group titles */
        root.addEventListener("keydown", function (e) {
            var el = e.target.closest("[contenteditable]");
            if (!el) { return; }
            if (e.key === "Enter") { e.preventDefault(); el.blur(); }
            if (e.key === "Escape") { el.textContent = el.dataset.orig || ""; el.blur(); }
        });
        root.addEventListener("paste", function (e) {
            var el = e.target.closest("[contenteditable]");
            if (!el) { return; }
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData("text").replace(/\s+/g, " ");
            document.execCommand("insertText", false, text);
        });
        root.addEventListener("focusout", function (e) {
            var el = e.target;
            if (!el.matches || !el.matches("[contenteditable]")) { return; }
            var value = el.textContent.replace(/\s+/g, " ").trim();
            el.textContent = value;
            if (value === (el.dataset.orig || "")) { return; }

            var req;
            if (el.hasAttribute("data-org-meta")) {
                req = { action: "meta", key: el.getAttribute("data-org-meta"), value: value };
            } else {
                if (value === "") { el.textContent = el.dataset.orig || ""; fail("The group title cannot be empty."); return; }
                req = { action: "group_update", id: el.closest("[data-group-id]").getAttribute("data-group-id"), title: value };
            }
            post(req).then(function (json) {
                if (typeof json.value === "string") { el.textContent = json.value; }
                el.dataset.orig = el.textContent.trim();
                if (el.classList.contains("dms-org-sub")) { el.classList.toggle("is-empty", el.textContent.trim() === ""); }
                toast("Saved");
            }).catch(function (err) {
                el.textContent = el.dataset.orig || "";
                fail(err.message);
            });
        });

        /* group colour */
        root.addEventListener("input", function (e) {
            if (!e.target.matches("[data-org-color]")) { return; }
            e.target.closest(".dms-org-group").style.setProperty("--org-color", e.target.value);
        });
        root.addEventListener("change", function (e) {
            if (!e.target.matches("[data-org-color]")) { return; }
            var group = e.target.closest("[data-group-id]");
            post({ action: "group_update", id: group.getAttribute("data-group-id"), color: e.target.value })
                .then(function () { toast("Colour saved"); })
                .catch(function (err) { fail(err.message); });
        });

        /* buttons */
        root.addEventListener("click", function (e) {
            var btn = e.target.closest("button");
            if (!btn || !root.contains(btn)) { return; }
            var group = btn.closest("[data-group-id]");
            var member = btn.closest(".dms-org-member");

            if (btn.hasAttribute("data-org-gmove")) {
                post({ action: "group_move", id: group.getAttribute("data-group-id"), dir: btn.getAttribute("data-org-gmove") })
                    .then(reloadKeepingEdit).catch(function (err) { fail(err.message); });
            } else if (btn.hasAttribute("data-org-gdelete")) {
                deleteGroup(group);
            } else if (btn.hasAttribute("data-org-gadd")) {
                addGroup();
            } else if (btn.hasAttribute("data-org-photo")) {
                e.stopPropagation();
                pickPhoto(member);
            } else if (btn.hasAttribute("data-org-medit")) {
                if (typeof window.editOfficial === "function") { window.editOfficial(member.getAttribute("data-official-id")); }
            } else if (btn.hasAttribute("data-org-mmove")) {
                stepMember(member, parseInt(btn.getAttribute("data-org-mmove"), 10));
            } else if (btn.hasAttribute("data-org-quickadd")) {
                openQuickAdd(group.getAttribute("data-group-id"));
            }
        });

        function groupTitle(group) {
            var t = group.querySelector("[data-org-gtitle]");
            return t ? t.textContent.trim() : "this group";
        }

        function deleteGroup(group) {
            var groups = Array.prototype.slice.call(root.querySelectorAll(".dms-org-group"));
            var i = groups.indexOf(group);
            var target = groups[i > 0 ? i - 1 : 1];
            var n = group.querySelectorAll(".dms-org-member").length;
            var text = n
                ? n + " official" + (n === 1 ? "" : "s") + " in it will be moved to “" + groupTitle(target) + "”."
                : "The group is empty.";
            var go = function () {
                post({ action: "group_delete", id: group.getAttribute("data-group-id") })
                    .then(reloadKeepingEdit).catch(function (err) { fail(err.message); });
            };
            if (!swalOk()) { if (confirm("Delete group “" + groupTitle(group) + "”? " + text)) { go(); } return; }
            Swal.fire({
                icon: "warning", title: "Delete “" + groupTitle(group) + "”?", text: text,
                showCancelButton: true, confirmButtonText: "Delete group", confirmButtonColor: "#d33"
            }).then(function (r) { if (r.isConfirmed) { go(); } });
        }

        function addGroup() {
            var go = function (title) {
                post({ action: "group_add", title: title || "" }).then(reloadKeepingEdit).catch(function (err) { fail(err.message); });
            };
            if (!swalOk()) { go(prompt("Group title", "New Group")); return; }
            Swal.fire({
                title: "Add group", input: "text", inputLabel: "Group title",
                inputPlaceholder: "e.g. Secretariat", inputValue: "",
                showCancelButton: true, confirmButtonText: "Add group",
                inputValidator: function (v) { return v.trim() === "" ? "Enter a title." : undefined; }
            }).then(function (r) { if (r.isConfirmed) { go(r.value.trim()); } });
        }

        /* photo change */
        var picker = document.createElement("input");
        picker.type = "file";
        picker.accept = "image/png,image/jpeg,image/gif,image/webp";
        picker.hidden = true;
        document.body.appendChild(picker);
        var pickFor = null;

        function pickPhoto(member) {
            pickFor = member;
            picker.value = "";
            picker.click();
        }

        picker.addEventListener("change", function () {
            var file = picker.files[0];
            var member = pickFor;
            if (!file || !member) { return; }
            var fd = new FormData();
            fd.append("action", "official_photo");
            fd.append("id", member.getAttribute("data-official-id"));
            fd.append("photo", file);
            member.classList.add("is-busy");
            post(fd).then(function (json) {
                var photo = member.querySelector(".dms-org-photo");
                var img = photo.querySelector(".dms-org-img");
                var initials = photo.querySelector(".dms-org-initials");
                if (!img) {
                    img = document.createElement("span");
                    img.className = "dms-org-img";
                    img.setAttribute("role", "img");
                    img.setAttribute("aria-label", member.querySelector(".dms-org-name").textContent);
                    photo.insertBefore(img, photo.firstChild);
                }
                if (initials) { initials.remove(); }
                img.style.backgroundImage = "url('" + json.photo_path.replace(/'/g, "%27") + "')";
                var data = officialData(member);
                data.photo_path = json.photo_path;
                member.setAttribute("data-official", JSON.stringify(data));
                toast("Photo updated");
            }).catch(function (err) {
                fail(err.message);
            }).finally(function () {
                member.classList.remove("is-busy");
            });
        });

        /* re-ordering */
        function membersOf(list) {
            return Array.prototype.slice.call(list.querySelectorAll(":scope > .dms-org-member"));
        }

        function saveOrder(member) {
            var list = member.closest("[data-org-members]");
            var group = member.closest("[data-group-id]");
            var order = membersOf(list).map(function (li) { return li.getAttribute("data-official-id"); });
            lines(root);
            refreshSolo();
            return post({ action: "official_move", id: member.getAttribute("data-official-id"), group_id: group.getAttribute("data-group-id"), order: order.join(",") })
                .then(function () { toast("Order saved"); })
                .catch(function (err) { fail(err.message + " Reloading the chart."); setTimeout(reloadKeepingEdit, 1500); });
        }

        function refreshSolo() {
            root.querySelectorAll("[data-org-members]").forEach(function (list) {
                var m = membersOf(list);
                m.forEach(function (li) { li.classList.toggle("is-solo", m.length === 1); });
            });
        }

        function appendMember(list, member, atStart) {
            var add = list.querySelector(":scope > .dms-org-add");
            if (atStart) { list.insertBefore(member, list.firstChild); }
            else { list.insertBefore(member, add); }
        }

        function stepMember(member, dir) {
            var list = member.closest("[data-org-members]");
            var siblings = membersOf(list);
            var i = siblings.indexOf(member);
            var j = i + dir;
            if (j >= 0 && j < siblings.length) {
                list.insertBefore(member, dir < 0 ? siblings[j] : siblings[j].nextSibling);
            } else {
                // Past the end of the group: move into the previous / next group.
                var groups = Array.prototype.slice.call(root.querySelectorAll(".dms-org-group"));
                var g = groups.indexOf(member.closest(".dms-org-group")) + dir;
                if (g < 0 || g >= groups.length) { return; }
                appendMember(groups[g].querySelector("[data-org-members]"), member, dir > 0);
            }
            var btn = member.querySelector('[data-org-mmove="' + dir + '"]');
            if (btn) { btn.focus(); }
            saveOrder(member);
        }

        var dragging = null;
        root.addEventListener("dragstart", function (e) {
            var li = e.target.closest && e.target.closest(".dms-org-member");
            if (!li || !root.classList.contains("is-editing")) { return; }
            dragging = li;
            li.classList.add("is-dragging");
            e.dataTransfer.effectAllowed = "move";
            try { e.dataTransfer.setData("text/plain", li.getAttribute("data-official-id")); } catch (err) { /* IE */ }
        });
        root.addEventListener("dragover", function (e) {
            if (!dragging) { return; }
            var list = e.target.closest && e.target.closest("[data-org-members]");
            if (!list) { return; }
            e.preventDefault();
            e.dataTransfer.dropEffect = "move";
            var before = null;
            membersOf(list).some(function (li) {
                if (li === dragging) { return false; }
                var r = li.getBoundingClientRect();
                if (e.clientY < r.top || (e.clientY <= r.bottom && e.clientX < r.left + r.width / 2)) {
                    before = li;
                    return true;
                }
                return false;
            });
            if (before) {
                if (dragging.nextSibling !== before) { list.insertBefore(dragging, before); }
            } else {
                var add = list.querySelector(":scope > .dms-org-add");
                if (dragging.parentNode !== list || dragging.nextSibling !== add) { list.insertBefore(dragging, add); }
            }
        });
        root.addEventListener("drop", function (e) {
            if (dragging) { e.preventDefault(); }
        });
        root.addEventListener("dragend", function () {
            if (!dragging) { return; }
            var li = dragging;
            dragging = null;
            li.classList.remove("is-dragging");
            justDragged = true;
            setTimeout(function () { justDragged = false; }, 50);
            saveOrder(li);
        });

        /* quick add */
        var modalEl = document.getElementById("orgQuickAdd");
        var form = modalEl ? modalEl.querySelector("form") : null;

        function openQuickAdd(groupId) {
            if (!modalEl || !form) { return; }
            form.reset();
            var sel = form.querySelector('[name="group_id"]');
            if (sel) { sel.value = groupId; }
            var prev = form.querySelector("[data-qa-preview]");
            if (prev) { prev.style.backgroundImage = ""; prev.classList.remove("has-photo"); }
            form.querySelectorAll("[data-qa-default]").forEach(function (i) { i.value = i.getAttribute("data-qa-default"); });
            if (window.bootstrap && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else if (window.jQuery) {
                jQuery(modalEl).modal("show");
            }
            setTimeout(function () { var f = form.querySelector('[name="firstname"]'); if (f) { f.focus(); } }, 350);
        }

        if (form) {
            var photoInput = form.querySelector('[name="photo"]');
            var preview = form.querySelector("[data-qa-preview]");
            if (photoInput && preview) {
                photoInput.addEventListener("change", function () {
                    var f = photoInput.files[0];
                    if (!f) { preview.style.backgroundImage = ""; preview.classList.remove("has-photo"); return; }
                    var reader = new FileReader();
                    reader.onload = function (ev) {
                        preview.style.backgroundImage = "url('" + ev.target.result + "')";
                        preview.classList.add("has-photo");
                    };
                    reader.readAsDataURL(f);
                });
            }
            form.addEventListener("submit", function (e) {
                e.preventDefault();
                if (!form.reportValidity()) { return; }
                var submit = form.querySelector('[type="submit"]');
                busy(submit, true);
                var fd = new FormData(form);
                fd.append("action", "official_quick_add");
                post(fd).then(function () {
                    reloadKeepingEdit();
                }).catch(function (err) {
                    busy(submit, false);
                    fail(err.message);
                });
            });
        }
    }

    /* ---------- boot ---------- */
    function boot() {
        lines();
        // Re-measure rows when a chart's width changes (window resize, sidebar rail/drawer toggle).
        if (typeof window.ResizeObserver === "function") {
            var widths = new WeakMap();
            var ro = new ResizeObserver(function (entries) {
                entries.forEach(function (en) {
                    var w = Math.round(en.contentRect.width);
                    if (widths.get(en.target) !== w) {
                        widths.set(en.target, w);
                        lines(en.target);
                    }
                });
            });
            document.querySelectorAll(".dms-org").forEach(function (el) { ro.observe(el); });
        }
        window.addEventListener("load", function () { lines(); });
        var root = document.getElementById("orgChart");
        if (!root) { return; }
        initExport(root);
        initDetails(root);
        if (root.hasAttribute("data-org-editable")) { initEditing(root); }
        // Photos are backgrounds, so layout does not change when they load; fonts can, though.
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(function () { lines(); }); }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }

    window.dmsOrgLines = lines;
})();
