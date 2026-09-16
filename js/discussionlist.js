/**
 * Adds a "Locks at" column to the forum discussion list, positioned right
 * before the "Started by" column, populated from local_forumlock's
 * schedule data via ajax.php. If the forum has no locked/scheduled
 * discussions at all, no column is added.
 *
 * Dates are pre-formatted server-side (in ajax.php) using userdate(), so
 * they reflect the viewing user's actual Moodle timezone preference rather
 * than just the browser's system clock.
 *
 * NOTE: Moodle's discussion list markup varies by version/theme. This
 * looks for a <th>/<td> whose text is "Started by" to find the insertion
 * point. If your theme labels that column differently, adjust
 * STARTED_BY_TEXT below, or paste the list's HTML back for a tighter fix.
 */
(function() {
    'use strict';

    var STARTED_BY_TEXT = 'started by';

    function init() {
        var cfg = window.localForumlock;
        if (!cfg || !cfg.ajaxurl) {
            return;
        }

        var url = cfg.ajaxurl + '?forumid=' + encodeURIComponent(cfg.forumid) +
            '&sesskey=' + encodeURIComponent(cfg.sesskey);

        fetch(url, {credentials: 'same-origin'})
            .then(function(response) {
                return response.ok ? response.json() : [];
            })
            .then(function(schedules) {
                if (schedules && schedules.length) {
                    annotate(schedules);
                }
                // If there's nothing scheduled/locked anywhere in this forum,
                // we deliberately do nothing - no column is added at all.
            })
            .catch(function() {
                // Non-critical UI enhancement - fail quietly.
            });
    }

    function discussionIdFromHref(href) {
        var match = href.match(/[?&]d=(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    }

    function findStartedByIndex(headerCells) {
        for (var i = 0; i < headerCells.length; i++) {
            if (headerCells[i].textContent.trim().toLowerCase().indexOf(STARTED_BY_TEXT) !== -1) {
                return i;
            }
        }
        return -1;
    }

    function cellText(schedule) {
        if (!schedule) {
            return window.localForumlock && window.localForumlock.strings ?
                window.localForumlock.strings.noneplaceholder : '\u2014';
        }
        return schedule.label;
    }

    function annotate(schedules) {
        var map = {};
        schedules.forEach(function(s) {
            map[s.discussionid] = s;
        });

        var links = document.querySelectorAll('a[href*="discuss.php"]');
        var table = null;
        var rowsByDiscussion = {};

        links.forEach(function(link) {
            var did = discussionIdFromHref(link.getAttribute('href') || '');
            if (!did || rowsByDiscussion[did]) {
                return;
            }
            var row = link.closest('tr');
            if (!row) {
                return;
            }
            rowsByDiscussion[did] = row;
            if (!table) {
                table = row.closest('table');
            }
        });

        if (!table) {
            // Theme doesn't use a table-based list - nothing safe to do here
            // without risking a badly-placed badge, so skip entirely rather
            // than guess.
            return;
        }

        var headerRow = table.querySelector('thead tr') || table.querySelector('tr');
        if (!headerRow) {
            return;
        }
        var headerCells = headerRow.querySelectorAll('th, td');
        var insertIndex = findStartedByIndex(headerCells);
        if (insertIndex === -1) {
            // Can't find the anchor column - append at the end instead of
            // guessing a position.
            insertIndex = headerCells.length;
        }

        if (!headerRow.querySelector('.forumlock-col')) {
            var th = document.createElement('th');
            th.className = 'forumlock-col';
            th.scope = 'col';
            th.textContent = (window.localForumlock && window.localForumlock.strings) ?
                window.localForumlock.strings.header : 'Locks at';
            var headerRefCell = headerRow.children[insertIndex] || null;
            headerRow.insertBefore(th, headerRefCell);
        }

        Object.keys(rowsByDiscussion).forEach(function(did) {
            var row = rowsByDiscussion[did];
            if (row.querySelector('.forumlock-col')) {
                return;
            }
            var td = document.createElement('td');
            td.className = 'forumlock-col';
            td.style.whiteSpace = 'pre-line';
            td.textContent = cellText(map[did]);
            var refCell = row.children[insertIndex] || null;
            row.insertBefore(td, refCell);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
