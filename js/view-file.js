/**
 * "View File" button on the view pages (resolution, ordinance, order of business, committee report).
 *
 * Attachments are stored as plain paths such as "uploads/RES. 031 ... 20% FUND.pdf", so the file is
 * opened directly whatever its type. Each path segment is URL-encoded, otherwise names containing
 * "%", "#" or "?" are misread by the browser and the server answers 404 / 400.
 *
 * The attachment input may carry data-exists="0" (set by PHP when the file is not in uploads/);
 * in that case a message is shown instead of opening a page that would only say "Not Found".
 */
(function () {
    function notify(icon, title, text) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({ icon: icon, title: title, text: text });
        } else {
            window.alert(title + '\n\n' + text);
        }
    }

    function encodePath(path) {
        if (/^https?:\/\//i.test(path)) {
            return path; // already a full URL
        }
        return path.split('/').map(function (part) {
            return encodeURIComponent(part);
        }).join('/');
    }

    window.viewFile = function (id, field) {
        var input = document.getElementById(field);
        var filePath = input ? String(input.value || '').trim() : '';

        if (!filePath) {
            notify('info', 'No file attached', 'This record has no attachment yet. Use Edit to upload one.');
            return;
        }

        if (input.getAttribute('data-exists') === '0') {
            notify('warning', 'File missing',
                'The attached file "' + filePath.replace(/^uploads\//, '') + '" is not on the server. ' +
                'Please re-upload it using Edit.');
            return;
        }

        window.open(encodePath(filePath), '_blank');
    };
})();
