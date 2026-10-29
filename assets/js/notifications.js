(() => {
    const center = document.querySelector('.notification-center');
    if (!center) return;

    const toggle = center.querySelector('.notification-toggle');
    const badge = center.querySelector('.notification-badge');
    const popover = center.querySelector('.notification-popover');
    const list = center.querySelector('.notification-list');
    const toast = center.querySelector('.notification-toast');
    const csrfToken = center.dataset.csrf || '';
    const seen = new Set();
    let firstLoad = true;
    let toastTimer = null;

    const showToast = (message) => {
        toast.textContent = message;
        toast.hidden = false;
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => { toast.hidden = true; }, 6000);
    };

    const markRead = async (id) => {
        const body = new URLSearchParams({ csrf_token: csrfToken, id: String(id) });
        await fetch(center.dataset.markRead, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body,
        });
    };

    const render = (items) => {
        list.replaceChildren();
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'notification-empty';
            empty.textContent = center.dataset.empty || 'No new notifications.';
            list.append(empty);
            return;
        }

        for (const item of items) {
            const link = document.createElement('a');
            let target;
            try {
                target = new URL(item.link, window.location.href);
            } catch {
                continue;
            }
            if (target.origin !== window.location.origin) continue;
            link.href = target.href;
            link.className = 'notification-item';

            const title = document.createElement('strong');
            title.textContent = item.title;
            const message = document.createElement('span');
            message.textContent = item.message;
            const time = document.createElement('small');
            const timestamp = new Date(String(item.created_at).replace(' ', 'T'));
            time.textContent = Number.isNaN(timestamp.getTime())
                ? ''
                : timestamp.toLocaleString(document.documentElement.lang || undefined);
            link.append(title, message, time);
            link.addEventListener('click', async (event) => {
                event.preventDefault();
                try { await markRead(item.id); } catch { /* Continue to the linked page. */ }
                window.location.assign(target.href);
            });
            list.append(link);
        }
    };

    const refresh = async () => {
        try {
            const response = await fetch(center.dataset.feed, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            const items = Array.isArray(data.notifications) ? data.notifications : [];
            const newItems = items.filter((item) => !seen.has(String(item.id)));
            if (!firstLoad && newItems.length) showToast(newItems[0].title);
            items.forEach((item) => seen.add(String(item.id)));
            firstLoad = false;
            badge.textContent = data.unread_count > 99 ? '99+' : String(data.unread_count || 0);
            badge.hidden = !data.unread_count;
            render(items);
        } catch {
            // Keep the page usable if the notification service is temporarily unavailable.
        }
    };

    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(open));
        popover.hidden = !open;
    });
    document.addEventListener('click', (event) => {
        if (!center.contains(event.target)) {
            toggle.setAttribute('aria-expanded', 'false');
            popover.hidden = true;
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            toggle.setAttribute('aria-expanded', 'false');
            popover.hidden = true;
        }
    });

    refresh();
    window.setInterval(() => {
        if (!document.hidden) refresh();
    }, 15000);
})();
