// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Shows a small banner near the top of a discussion's own page (discuss.php)
 * indicating whether it's already locked or scheduled to lock, and when.
 *
 * NOTE: looks for the first element with class "forumpost" as the anchor
 * point to insert before (that's the wrapper around each post in mod_forum's
 * default rendering). Falls back to the main content region if that class
 * isn't found in your theme.
 */

const render = (data) => {
    if (!data) {
        return;
    }

    let banner = document.createElement('div');
    banner.className = 'alert ' + (data.status === 'applied' ? 'alert-secondary' : 'alert-warning');
    banner.style.whiteSpace = 'pre-line';
    banner.textContent = data.message;

    let anchor = document.querySelector('.forumpost') ||
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
};

/**
 * @param {Object} data {status, message} for this discussion.
 */
export const init = (data) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => render(data));
    } else {
        render(data);
    }
};
