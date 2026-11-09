// Anonymous visitor chat. No inline script (CSP), no framework. Messages are written with textContent only.
(function () {
    var chat = document.querySelector('.chat');
    if (!chat) { return; }

    var list = chat.querySelector('.thread');
    var empty = chat.querySelector('.empty');
    var form = chat.querySelector('.composer');
    var box = form.querySelector('textarea');
    var button = form.querySelector('button');
    var errorBox = chat.querySelector('.error');
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var interval = parseInt(chat.dataset.pollMs, 10) || 5000;

    var lastId = 0;
    var seen = {};
    var timer = null;
    var stopped = false;
    var polling = false;

    function showError(text) {
        errorBox.textContent = text;
        errorBox.hidden = false;
    }

    function clock(iso) {
        try {
            return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Istanbul' });
        } catch (e) {
            return '';
        }
    }

    // Blocked, stealthed, expired: all of them end here with the same neutral wording.
    function stop(text) {
        stopped = true;
        window.clearTimeout(timer);
        form.hidden = true;
        empty.hidden = true;
        showError(text);
    }

    // lastId only advances for polled messages: the visitor's own send must not skip an owner reply with a lower id.
    function render(message) {
        if (seen[message.id]) { return; }
        seen[message.id] = true;

        var mine = message.sender === 'visitor';
        var item = document.createElement('li');
        item.className = 'bubble ' + (mine ? 'mine' : 'theirs');

        var who = document.createElement('span');
        who.className = 'meta';
        who.textContent = (mine ? chat.dataset.you : chat.dataset.owner) + ' · ' + clock(message.created_at);

        var text = document.createElement('p');
        if (message.hidden) {
            text.className = 'removed';
            text.textContent = chat.dataset.removed;
        } else {
            text.textContent = message.body;
        }

        item.appendChild(who);
        item.appendChild(text);

        if (!mine && !message.hidden) {
            var report = document.createElement('button');
            report.type = 'button';
            report.className = 'report';
            report.textContent = chat.dataset.report;
            report.addEventListener('click', function () { reportMessage(message.id, report); });
            item.appendChild(report);
        }

        item.dataset.id = message.id;
        var before = null;
        Array.prototype.some.call(list.children, function (node) {
            if (parseInt(node.dataset.id, 10) > message.id) { before = node; return true; }
            return false;
        });
        list.insertBefore(item, before);
        empty.hidden = true;
    }

    function request(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, options.headers || {});
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok) {
                    var error = new Error('status');
                    error.status = response.status;
                    error.data = data;
                    throw error;
                }
                return data;
            });
        });
    }

    function failure(error) {
        if (error.status === 403 && error.data && error.data.code === 'chat_unavailable') {
            stop(chat.dataset.unavailable);
        } else if (error.status === 403 && error.data && error.data.message) {
            stop(error.data.message);
        } else if (error.status === 419) {
            showError(chat.dataset.expired);
        } else if (error.status === 429) {
            showError(chat.dataset.limited);
        } else if (error.status === 422 && error.data && error.data.message && error.data.code === 'message_blocked') {
            showError(error.data.message);
        } else if (error.status === 422 && error.data && error.data.errors && error.data.errors.body) {
            showError(error.data.errors.body[0]);
        } else {
            showError(chat.dataset.error);
        }
    }

    function poll() {
        if (stopped || polling) { return; }
        polling = true;
        request(chat.dataset.messagesUrl + '?after=' + lastId).then(function (data) {
            data.data.forEach(function (message) {
                render(message);
                lastId = Math.max(lastId, message.id);
            });
            if (data.data.length) { list.lastElementChild.scrollIntoView({ block: 'nearest' }); }
        }).catch(function (error) {
            if (error.status === 403) { failure(error); }
        }).then(function () {
            polling = false;
            schedule();
        });
    }

    // Poll every few seconds while the page is open, and not at all while it is hidden.
    function schedule() {
        window.clearTimeout(timer);
        if (stopped || document.hidden) { return; }
        timer = window.setTimeout(poll, interval);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearTimeout(timer);
        } else {
            poll();
        }
    });

    function reportMessage(id, control) {
        if (!window.confirm(chat.dataset.reportConfirm)) { return; }
        control.disabled = true;
        request(chat.dataset.reportUrl.replace(':id', id), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf }
        }).then(function () {
            control.textContent = chat.dataset.reported;
        }).catch(function (error) {
            control.disabled = false;
            failure(error);
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var body = box.value.trim();
        if (!body || button.disabled) { return; }

        button.disabled = true;
        button.textContent = chat.dataset.sending;
        errorBox.hidden = true;

        var payload = { body: body };
        var consent = form.querySelector('[name="consent"]');
        if (consent) { payload.consent = consent.checked; }

        request(form.getAttribute('action'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload)
        }).then(function (message) {
            box.value = '';
            render(message);
            list.lastElementChild.scrollIntoView({ block: 'nearest' });
        }).catch(failure).then(function () {
            button.disabled = false;
            button.textContent = chat.dataset.send;
            box.focus();
        });
    });

    poll();
})();
