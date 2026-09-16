/**
 * Shows a small banner near the top of a discussion's own page (discuss.php)
 * indicating whether it's already locked or scheduled to lock, and when.
 *
 * NOTE: looks for the first element with class "forumpost" as the anchor
 * point to insert before (that's the wrapper around each post in mod_forum's
 * default rendering). Falls back to the main content region if that class
 * isn't found in your theme.
 */
(function() {
    'use strict';

    function init() {
        var data = window.localForumlockBanner;
        if (!data) {
            return;
        }

        var banner = document.createElement('div');
        banner.className = 'alert ' + (data.status === 'applied' ? 'alert-secondary' : 'alert-warning');
        banner.style.whiteSpace = 'pre-line';
        banner.textContent = data.message;

        var anchor = document.querySelector('.forumpost') ||
            document.querySelector('[data-region="discussion-nested-view"]') ||
            document.querySelector('#region-main');

        if (!anchor) {
            return;
        }

        if (anchor.id === 'region-main') {
            anchor.insertBefore(banner, anchor.firstChild);
        } else if (anchor.parentNode) {
            anchor.parentNode.insertBefore(banner, anchor);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
