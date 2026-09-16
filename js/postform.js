/**
 * Adds a "Lock discussion" section to the mod_forum post form, styled to
 * match the native "Display period" section, without touching core files.
 *
 * Two independent hidden fields ride along with the form's normal POST:
 * local_forumlock_lockat (when replies stop) and local_forumlock_openat
 * (optional - when replies start, for a delayed-open window). mod_forum's
 * own moodleform ignores fields it doesn't know about, so nothing breaks -
 * local_forumlock\observer reads both off the raw request once the post
 * has been saved.
 *
 * NOTE: Moodle theme markup does shift between versions/themes. This script
 * looks for a <legend> containing "Display period" as its anchor point, and
 * falls back to inserting just above the submit buttons if that text isn't
 * found. If your theme's wording differs, adjust DISPLAY_PERIOD_TEXT below.
 */
(function() {
    'use strict';

    var DISPLAY_PERIOD_TEXT = 'display period';
    var LOCK_FIELD_NAME = 'local_forumlock_lockat';
    var OPEN_FIELD_NAME = 'local_forumlock_openat';

    function findDisplayPeriodFieldset() {
        var legends = document.querySelectorAll('legend');
        for (var i = 0; i < legends.length; i++) {
            if (legends[i].textContent.trim().toLowerCase().indexOf(DISPLAY_PERIOD_TEXT) !== -1) {
                return legends[i].closest('fieldset');
            }
        }
        return null;
    }

    function findSubmitRow(form) {
        var submit = form.querySelector('[id*="submitbutton"], [name*="submitbutton"]');
        return submit ? submit.closest('.fitem, .form-group, fieldset') : null;
    }

    function buildHourOptions() {
        var out = '';
        for (var h = 0; h < 24; h++) {
            var hh = h < 10 ? '0' + h : String(h);
            out += '<option value="' + hh + '">' + hh + '</option>';
        }
        return out;
    }

    function buildMinuteOptions() {
        return ['00', '15', '30', '45'].map(function(m) {
            return '<option value="' + m + '">' + m + '</option>';
        }).join('');
    }

    /**
     * Builds one "date + hour + minute" row (used for both Lock at and
     * Opens at), returning references to its inputs.
     */
    function buildDateTimeRow(idPrefix, labelText, helpText) {
        var row = document.createElement('div');
        row.className = 'fitem';
        row.style.display = 'none';

        var label = document.createElement('label');
        label.setAttribute('for', idPrefix + '_date');
        label.textContent = labelText;
        row.appendChild(label);

        row.insertAdjacentHTML('beforeend',
            ' <input type="date" id="' + idPrefix + '_date" class="form-control d-inline-block w-auto"> ' +
            '<select id="' + idPrefix + '_hour" class="form-control d-inline-block w-auto">' +
            buildHourOptions() + '</select> : ' +
            '<select id="' + idPrefix + '_minute" class="form-control d-inline-block w-auto">' +
            buildMinuteOptions() + '</select>');

        if (helpText) {
            var help = document.createElement('div');
            help.className = 'form-text text-muted';
            help.textContent = helpText;
            row.appendChild(help);
        }

        var dateInput = row.querySelector('#' + idPrefix + '_date');
        if (dateInput && !dateInput.value) {
            var now = new Date();
            dateInput.value = now.getFullYear() + '-' +
                String(now.getMonth() + 1).padStart(2, '0') + '-' +
                String(now.getDate()).padStart(2, '0');
        }

        return {
            row: row,
            dateInput: dateInput,
            hourSelect: row.querySelector('#' + idPrefix + '_hour'),
            minuteSelect: row.querySelector('#' + idPrefix + '_minute'),
        };
    }

    function readDateTimeRow(fields) {
        if (fields.dateInput && fields.dateInput.value && fields.hourSelect && fields.minuteSelect) {
            var iso = fields.dateInput.value + 'T' + fields.hourSelect.value + ':' + fields.minuteSelect.value + ':00';
            var ts = Math.floor(new Date(iso).getTime() / 1000);
            return isNaN(ts) ? 0 : ts;
        }
        return 0;
    }

    // NOTE: this is a self-contained collapsible, styled to look like a
    // native Moodle collapsible fieldset (chevron + legend header). It does
    // NOT hook into whatever collapse JS your theme already initialised at
    // page load, because that JS typically only binds to fieldsets present
    // in the DOM at load time - ours is added afterward. It's a visual
    // approximation, not a literal reuse of core's collapse behaviour.
    function buildFieldset() {
        var strings = window.localForumlockStrings || {};
        var fieldset = document.createElement('fieldset');
        fieldset.className = 'clearfix collapsible';
        fieldset.id = 'id_forumlock';

        var header = document.createElement('legend');
        header.className = 'forumlock-header';
        header.style.cursor = 'pointer';
        header.style.display = 'flex';
        header.style.alignItems = 'center';
        header.style.userSelect = 'none';
        header.setAttribute('role', 'button');
        header.setAttribute('tabindex', '0');
        header.setAttribute('aria-expanded', 'false');

        var caret = document.createElement('i');
        caret.className = 'icon fa fa-caret-right forumlock-caret';
        caret.setAttribute('aria-hidden', 'true');
        caret.style.marginRight = '0.4rem';
        caret.style.transition = 'transform 0.15s ease-in-out';

        var title = document.createElement('span');
        title.textContent = strings.header || 'Lock discussion';

        header.appendChild(caret);
        header.appendChild(title);
        fieldset.appendChild(header);

        var body = document.createElement('div');
        body.className = 'forumlock-body';
        body.style.display = 'none';
        body.style.marginTop = '0.5rem';

        // --- Lock at ---
        var lockEnableRow = document.createElement('div');
        lockEnableRow.className = 'fitem';
        var lockEnableLabel = document.createElement('label');
        lockEnableLabel.className = 'form-check-label';
        lockEnableLabel.setAttribute('for', 'id_forumlock_enable');
        lockEnableLabel.textContent = strings.enable || 'Lock this discussion at a set time';
        var lockEnableCheckbox = document.createElement('input');
        lockEnableCheckbox.type = 'checkbox';
        lockEnableCheckbox.className = 'form-check-input';
        lockEnableCheckbox.id = 'id_forumlock_enable';
        var lockEnableWrap = document.createElement('div');
        lockEnableWrap.className = 'form-check';
        lockEnableWrap.appendChild(lockEnableCheckbox);
        lockEnableWrap.appendChild(lockEnableLabel);
        lockEnableRow.appendChild(lockEnableWrap);
        body.appendChild(lockEnableRow);

        var lockRow = buildDateTimeRow('id_forumlock', strings.lockat || 'Lock at',
            strings.help || 'Students will still be able to read the discussion after this time, ' +
            'but will no longer be able to reply.');
        body.appendChild(lockRow.row);

        // --- Opens at (optional, nested under Lock at) ---
        var openEnableRow = document.createElement('div');
        openEnableRow.className = 'fitem';
        openEnableRow.style.display = 'none';
        openEnableRow.style.marginTop = '0.5rem';
        var openEnableLabel = document.createElement('label');
        openEnableLabel.className = 'form-check-label';
        openEnableLabel.setAttribute('for', 'id_forumlock_open_enable');
        openEnableLabel.textContent = strings.openenable || 'Also delay when replies open until a set time';
        var openEnableCheckbox = document.createElement('input');
        openEnableCheckbox.type = 'checkbox';
        openEnableCheckbox.className = 'form-check-input';
        openEnableCheckbox.id = 'id_forumlock_open_enable';
        var openEnableWrap = document.createElement('div');
        openEnableWrap.className = 'form-check';
        openEnableWrap.appendChild(openEnableCheckbox);
        openEnableWrap.appendChild(openEnableLabel);
        openEnableRow.appendChild(openEnableWrap);
        body.appendChild(openEnableRow);

        var openRow = buildDateTimeRow('id_forumlock_open', strings.openat || 'Opens at',
            strings.openhelp || 'The discussion is visible the whole time, but students can\'t reply until this time.');
        body.appendChild(openRow.row);

        fieldset.appendChild(body);

        lockEnableCheckbox.addEventListener('change', function() {
            var checked = lockEnableCheckbox.checked;
            lockRow.row.style.display = checked ? '' : 'none';
            openEnableRow.style.display = checked ? '' : 'none';
            if (!checked) {
                openEnableCheckbox.checked = false;
                openRow.row.style.display = 'none';
            }
        });

        openEnableCheckbox.addEventListener('change', function() {
            openRow.row.style.display = openEnableCheckbox.checked ? '' : 'none';
        });

        function toggle() {
            var expanded = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            body.style.display = expanded ? 'none' : '';
            caret.style.transform = expanded ? 'rotate(0deg)' : 'rotate(90deg)';
        }

        header.addEventListener('click', toggle);
        header.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggle();
            }
        });

        return {
            fieldset: fieldset,
            lockEnableCheckbox: lockEnableCheckbox,
            lockRow: lockRow,
            openEnableCheckbox: openEnableCheckbox,
            openEnableRow: openEnableRow,
            openRow: openRow,
            body: body,
            header: header,
            caret: caret,
        };
    }

    function setMinuteSnapped(selectEl, minuteStr) {
        var allowed = ['00', '15', '30', '45'];
        var closest = allowed.reduce(function(best, opt) {
            return Math.abs(parseInt(opt, 10) - parseInt(minuteStr, 10)) <
                Math.abs(parseInt(best, 10) - parseInt(minuteStr, 10)) ? opt : best;
        }, '00');
        selectEl.value = closest;
    }

    function fillDateTimeRow(fields, isoDatetime) {
        var parts = isoDatetime.split('T');
        if (fields.dateInput && parts[0]) {
            fields.dateInput.value = parts[0];
        }
        if (parts[1]) {
            var hm = parts[1].split(':');
            if (fields.hourSelect && hm[0]) {
                fields.hourSelect.value = hm[0];
            }
            if (fields.minuteSelect && hm[1]) {
                setMinuteSnapped(fields.minuteSelect, hm[1]);
            }
        }
    }

    function applyPrefill(parts) {
        var data = window.localForumlockEdit;
        if (!data || !data.enabled) {
            return;
        }

        parts.lockEnableCheckbox.checked = true;
        if (data.datetime) {
            fillDateTimeRow(parts.lockRow, data.datetime);
        }
        parts.lockRow.row.style.display = '';

        if (data.openenabled) {
            parts.openEnableCheckbox.checked = true;
            parts.openEnableRow.style.display = '';
            if (data.opendatetime) {
                fillDateTimeRow(parts.openRow, data.opendatetime);
            }
            parts.openRow.row.style.display = '';
        } else {
            parts.openEnableRow.style.display = '';
        }

        parts.body.style.display = '';
        parts.header.setAttribute('aria-expanded', 'true');
        parts.caret.style.transform = 'rotate(90deg)';

        if (data.readonly) {
            [parts.lockEnableCheckbox, parts.lockRow.dateInput, parts.lockRow.hourSelect, parts.lockRow.minuteSelect,
                parts.openEnableCheckbox, parts.openRow.dateInput, parts.openRow.hourSelect, parts.openRow.minuteSelect]
                .forEach(function(el) {
                    if (el) {
                        el.disabled = true;
                    }
                });
            var note = document.createElement('div');
            note.className = 'form-text text-muted';
            note.textContent = (window.localForumlockStrings && window.localForumlockStrings.readonly) ||
                'This discussion is already locked as of the time above. To change it, ' +
                'unlock the discussion manually first, then set a new schedule.';
            parts.body.appendChild(note);
            parts.fieldset.setAttribute('data-forumlock-readonly', '1');
        }
    }

    function attach() {
        var form = document.querySelector('form[id^="mform"]') || document.querySelector('form.mform');
        if (!form) {
            return;
        }

        var parts = buildFieldset();
        var anchor = findDisplayPeriodFieldset();

        if (anchor && anchor.parentNode) {
            anchor.parentNode.insertBefore(parts.fieldset, anchor.nextSibling);
        } else {
            var submitRow = findSubmitRow(form);
            if (submitRow && submitRow.parentNode) {
                submitRow.parentNode.insertBefore(parts.fieldset, submitRow);
            } else {
                form.appendChild(parts.fieldset);
            }
        }

        applyPrefill(parts);

        var lockHidden = document.createElement('input');
        lockHidden.type = 'hidden';
        lockHidden.name = LOCK_FIELD_NAME;
        lockHidden.value = '0';
        form.appendChild(lockHidden);

        var openHidden = document.createElement('input');
        openHidden.type = 'hidden';
        openHidden.name = OPEN_FIELD_NAME;
        openHidden.value = '0';
        form.appendChild(openHidden);

        form.addEventListener('submit', function(submitEvent) {
            if (parts.fieldset.getAttribute('data-forumlock-readonly') === '1') {
                lockHidden.value = '0';
                openHidden.value = '0';
                return;
            }

            if (!parts.lockEnableCheckbox.checked) {
                lockHidden.value = '0';
                openHidden.value = '0';
                return;
            }

            var lockTs = readDateTimeRow(parts.lockRow);
            lockHidden.value = String(lockTs);

            if (parts.openEnableCheckbox.checked) {
                var openTs = readDateTimeRow(parts.openRow);
                if (openTs > 0 && lockTs > 0 && openTs >= lockTs) {
                    var strings = window.localForumlockStrings || {};
                    window.alert(strings.openaftererror || 'The opening time must be earlier than the locking time.');
                    submitEvent.preventDefault();
                    return;
                }
                openHidden.value = String(openTs);
            } else {
                openHidden.value = '0';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attach);
    } else {
        attach();
    }
})();
