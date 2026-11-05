// Reveals an emergency contact number on tap. No inline script (CSP), no framework.
(function () {
    var section = document.querySelector('.contacts');
    if (!section) { return; }
    var errorBox = section.querySelector('.error');

    function showError(text) {
        errorBox.textContent = text;
        errorBox.hidden = false;
    }

    section.addEventListener('click', function (event) {
        var button = event.target.closest('.reveal');
        if (!button || button.disabled) { return; }

        var label = button.textContent;
        button.disabled = true;
        button.textContent = section.dataset.busy;
        errorBox.hidden = true;

        fetch(button.dataset.url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'omit'
        }).then(function (response) {
            if (!response.ok) {
                var error = new Error('status');
                error.status = response.status;
                throw error;
            }
            return response.json();
        }).then(function (data) {
            var call = document.createElement('a');
            call.className = 'call';
            call.href = 'tel:' + String(data.phone).replace(/[^0-9+]/g, '');
            call.textContent = section.dataset.call + ' ' + data.phone;
            button.replaceWith(call);
        }).catch(function (error) {
            button.disabled = false;
            button.textContent = label;
            showError(error.status === 429 ? section.dataset.limited : section.dataset.error);
        });
    });
})();
