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
 * The date/time controls are built by CLONING the real day/month/year/
 * hour/minute <select> elements from the native "Display period" fields
 * (timestart/timeend), rather than guessing at theme CSS classes. That
 * way the styling matches whatever theme is actually installed, not just
 * the one this was designed against. If the clone source can't be found
 * (a very different theme, or Display period missing entirely), each
 * select falls back to a plain hand-built one so the section still works,
 * just without the native pill styling. Minute options are trimmed to
 * :00/:15/:30/:45 either way, to match this site's cron granularity.
 *
 * NOTE: Moodle theme markup does shift between versions/themes. This script
 * looks for a <legend> containing "Display period" as its anchor point, and
 * falls back to inserting just above the submit buttons if that text isn't
 * found. If your theme's wording differs, adjust DISPLAY_PERIOD_TEXT below.
 */

const DISPLAY_PERIOD_TEXT = 'display period';

let editData = null;
let langStrings = null;
let LOCK_FIELD_NAME = 'local_forumlock_lockat';
let OPEN_FIELD_NAME = 'local_forumlock_openat';
let MINUTE_OPTIONS = ['00', '15', '30', '45'];
let MINUTE_VALUES = [0, 15, 30, 45];

// Set once in attach(), before any row is built - the native "Display
// period" fieldset we clone day/month/year/hour/minute selects from.
let nativeAnchorFieldset = null;

/**
 * Find display period fieldset.
 */
function findDisplayPeriodFieldset() {
    let legends = document.querySelectorAll('legend');
    for (let i = 0; i < legends.length; i++) {
        if (legends[i].textContent.trim().toLowerCase().indexOf(DISPLAY_PERIOD_TEXT) !== -1) {
            return legends[i].closest('fieldset');
        }
    }
    return null;
}

/**
 * Find submit row.
 *
 * @param {*} form The form.
 */
function findSubmitRow(form) {
    let submit = form.querySelector('[id*="submitbutton"], [name*="submitbutton"]');
    return submit ? submit.closest('.fitem, .form-group, fieldset') : null;
}

/**
 * Finds a native date_time_selector <select> inside the "Display
 * period" fieldset by its id suffix (e.g. "day", "month"). Used as a
 * styling template so our own date fields pick up whatever pill/select
 * classes this theme actually uses, instead of a hardcoded guess.
 * @param {*} suffix The suffix.
 */
function getNativeSelect(suffix) {
    if (!nativeAnchorFieldset) {
        return null;
    }
    let selects = nativeAnchorFieldset.querySelectorAll('select');
    let needle = '_' + suffix;
    for (let i = 0; i < selects.length; i++) {
        let id = selects[i].id || '';
        if (id.length > needle.length && id.slice(-needle.length) === needle) {
            return selects[i];
        }
    }
    return null;
}

/**
 * Fallback select.
 *
 * @param {*} options The options.
 */
function fallbackSelect(options) {
    let select = document.createElement('select');
    select.className = 'form-select custom-select';
    options.forEach(function(opt) {
        let option = document.createElement('option');
        option.value = opt.value;
        option.textContent = opt.label;
        select.appendChild(option);
    });
    return select;
}

/**
 * Fallback day select.
 */
function fallbackDaySelect() {
    let options = [];
    for (let d = 1; d <= 31; d++) {
        options.push({value: String(d), label: String(d)});
    }
    return fallbackSelect(options);
}

/**
 * Fallback month select.
 */
function fallbackMonthSelect() {
    let names = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    let options = names.map(function(name, idx) {
        return {value: String(idx + 1), label: name};
    });
    return fallbackSelect(options);
}

/**
 * Fallback year select.
 */
function fallbackYearSelect() {
    let thisYear = new Date().getFullYear();
    let options = [];
    for (let y = thisYear; y <= thisYear + 5; y++) {
        options.push({value: String(y), label: String(y)});
    }
    return fallbackSelect(options);
}

/**
 * Fallback hour select.
 */
function fallbackHourSelect() {
    let options = [];
    for (let h = 0; h < 24; h++) {
        let hh = h < 10 ? '0' + h : String(h);
        options.push({value: hh, label: hh});
    }
    return fallbackSelect(options);
}

/**
 * Builds one day/month/year/hour/minute select, cloning the matching
 * native Display-period select for its styling/options when one can
 * be found, otherwise building a plain equivalent from scratch.
 * @param {*} suffix The suffix.
 * @param {*} idPrefix The id prefix.
 * @param {*} fallbackBuilder The fallback builder.
 */
function cloneOrBuildSelect(suffix, idPrefix, fallbackBuilder) {
    let native = getNativeSelect(suffix);
    let select;
    if (native) {
        select = native.cloneNode(true);
        select.removeAttribute('name');
        select.disabled = false;
        select.removeAttribute('required');
    } else {
        select = fallbackBuilder();
    }
    select.id = idPrefix + '_' + suffix;
    return select;
}

/**
 * Trims a minute <select> (cloned or fallback) down to :00/:15/:30/:45,
 * since that's how often this site actually checks for locks due, and
 * snaps whatever was previously selected to the nearest of those.
 * @param {*} minuteSelect The minute select.
 */
function restrictMinuteOptions(minuteSelect) {
    let previous = minuteSelect.value;
    Array.prototype.slice.call(minuteSelect.options).forEach(function(opt) {
        // Moodle's own minute options have unpadded values ("0", "15" ...), so compare as numbers.
        if (MINUTE_VALUES.indexOf(parseInt(opt.value, 10)) === -1) {
            opt.remove();
        }
    });
    if (!minuteSelect.options.length) {
        MINUTE_OPTIONS.forEach(function(m) {
            let opt = document.createElement('option');
            opt.value = m;
            opt.textContent = m;
            minuteSelect.appendChild(opt);
        });
    }
    setMinuteSnapped(minuteSelect, previous || '00');
}

/**
 * Builds one "day/month/year/hour/minute" row (used for both Lock at
 * and Opens at), returning references to its selects.
 * @param {*} idPrefix The id prefix.
 * @param {*} labelText The label text.
 * @param {*} helpText The help text.
 */
function buildDateTimeRow(idPrefix, labelText, helpText) {
    let row = document.createElement('div');
    row.className = 'fitem';
    row.style.display = 'none';

    let label = document.createElement('label');
    label.textContent = labelText;
    label.style.display = 'block';
    row.appendChild(label);

    let group = document.createElement('div');
    group.className = 'forumlock-date-group';
    group.style.display = 'flex';
    group.style.flexWrap = 'wrap';
    group.style.alignItems = 'center';
    group.style.columnGap = '0.4rem';
    group.style.rowGap = '0.25rem';
    group.style.marginTop = '0.25rem';

    let daySelect = cloneOrBuildSelect('day', idPrefix, fallbackDaySelect);
    let monthSelect = cloneOrBuildSelect('month', idPrefix, fallbackMonthSelect);
    let yearSelect = cloneOrBuildSelect('year', idPrefix, fallbackYearSelect);
    let hourSelect = cloneOrBuildSelect('hour', idPrefix, fallbackHourSelect);
    let minuteSelect = cloneOrBuildSelect('minute', idPrefix, fallbackSelect.bind(null, []));
    restrictMinuteOptions(minuteSelect);

    [daySelect, monthSelect, yearSelect, hourSelect, minuteSelect].forEach(function(select) {
        group.appendChild(select);
    });
    row.appendChild(group);

    if (helpText) {
        let help = document.createElement('div');
        help.className = 'form-text text-muted';
        help.textContent = helpText;
        row.appendChild(help);
    }

    // Default to "now" for anything that didn't come through with a
    // sensible value already selected (e.g. a fallback select with no
    // native default to inherit).
    let now = new Date();
    if (!daySelect.value) {
        daySelect.value = String(now.getDate());
    }
    if (!monthSelect.value) {
        monthSelect.value = String(now.getMonth() + 1);
    }
    if (!yearSelect.value) {
        yearSelect.value = String(now.getFullYear());
    }
    if (!hourSelect.value) {
        hourSelect.value = (now.getHours() < 10 ? '0' : '') + now.getHours();
    }

    return {
        row: row,
        daySelect: daySelect,
        monthSelect: monthSelect,
        yearSelect: yearSelect,
        hourSelect: hourSelect,
        minuteSelect: minuteSelect,
    };
}

/**
 * Read date time row.
 *
 * @param {*} fields The fields.
 */
function readDateTimeRow(fields) {
    if (fields.daySelect && fields.daySelect.value &&
            fields.monthSelect && fields.monthSelect.value &&
            fields.yearSelect && fields.yearSelect.value) {
        let d = new Date(
            parseInt(fields.yearSelect.value, 10),
            parseInt(fields.monthSelect.value, 10) - 1,
            parseInt(fields.daySelect.value, 10),
            fields.hourSelect ? (parseInt(fields.hourSelect.value, 10) || 0) : 0,
            fields.minuteSelect ? (parseInt(fields.minuteSelect.value, 10) || 0) : 0,
            0
        );
        let ts = Math.floor(d.getTime() / 1000);
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
/**
 * Build fieldset.
 */
function buildFieldset() {
    let strings = langStrings || {};
    let fieldset = document.createElement('fieldset');
    fieldset.className = 'clearfix collapsible';
    fieldset.id = 'id_forumlock';

    let header = document.createElement('legend');
    header.className = 'forumlock-header';
    header.style.cursor = 'pointer';
    header.style.display = 'flex';
    header.style.alignItems = 'center';
    header.style.userSelect = 'none';
    header.setAttribute('role', 'button');
    header.setAttribute('tabindex', '0');
    header.setAttribute('aria-expanded', 'false');

    let caret = document.createElement('i');
    caret.className = 'icon fa fa-caret-right forumlock-caret';
    caret.setAttribute('aria-hidden', 'true');
    caret.style.marginRight = '0.4rem';
    caret.style.transition = 'transform 0.15s ease-in-out';

    let title = document.createElement('span');
    title.textContent = strings.header || 'Lock discussion';

    header.appendChild(caret);
    header.appendChild(title);
    fieldset.appendChild(header);

    let body = document.createElement('div');
    body.className = 'forumlock-body';
    body.style.display = 'none';
    body.style.marginTop = '0.5rem';

    // --- Lock at ---
    let lockEnableRow = document.createElement('div');
    lockEnableRow.className = 'fitem';
    let lockEnableLabel = document.createElement('label');
    lockEnableLabel.className = 'form-check-label';
    lockEnableLabel.setAttribute('for', 'id_forumlock_enable');
    lockEnableLabel.textContent = strings.enable || 'Lock this discussion at a set time';
    let lockEnableCheckbox = document.createElement('input');
    lockEnableCheckbox.type = 'checkbox';
    lockEnableCheckbox.className = 'form-check-input';
    lockEnableCheckbox.id = 'id_forumlock_enable';
    let lockEnableWrap = document.createElement('div');
    lockEnableWrap.className = 'form-check';
    lockEnableWrap.appendChild(lockEnableCheckbox);
    lockEnableWrap.appendChild(lockEnableLabel);
    lockEnableRow.appendChild(lockEnableWrap);
    body.appendChild(lockEnableRow);

    let lockRow = buildDateTimeRow('id_forumlock', strings.lockat || 'Lock at',
        strings.help || 'Students will still be able to read the discussion after this time, ' +
        'but will no longer be able to reply.');
    body.appendChild(lockRow.row);

    // --- Opens at (optional, nested under Lock at) ---
    let openEnableRow = document.createElement('div');
    openEnableRow.className = 'fitem';
    openEnableRow.style.display = 'none';
    openEnableRow.style.marginTop = '0.5rem';
    let openEnableLabel = document.createElement('label');
    openEnableLabel.className = 'form-check-label';
    openEnableLabel.setAttribute('for', 'id_forumlock_open_enable');
    openEnableLabel.textContent = strings.openenable || 'Also delay when replies open until a set time';
    let openEnableCheckbox = document.createElement('input');
    openEnableCheckbox.type = 'checkbox';
    openEnableCheckbox.className = 'form-check-input';
    openEnableCheckbox.id = 'id_forumlock_open_enable';
    let openEnableWrap = document.createElement('div');
    openEnableWrap.className = 'form-check';
    openEnableWrap.appendChild(openEnableCheckbox);
    openEnableWrap.appendChild(openEnableLabel);
    openEnableRow.appendChild(openEnableWrap);
    body.appendChild(openEnableRow);

    let openRow = buildDateTimeRow('id_forumlock_open', strings.openat || 'Opens at',
        strings.openhelp || 'The discussion is visible the whole time, but students can\'t reply until this time.');
    body.appendChild(openRow.row);

    fieldset.appendChild(body);

    lockEnableCheckbox.addEventListener('change', function() {
        let checked = lockEnableCheckbox.checked;
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

    /**
     *
     */
    function toggle() {
        let expanded = header.getAttribute('aria-expanded') === 'true';
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

/**
 * Set minute snapped.
 *
 * @param {*} selectEl The select el.
 * @param {*} minuteStr The minute str.
 */
function setMinuteSnapped(selectEl, minuteStr) {
    let wanted = parseInt(minuteStr, 10) || 0;
    let closest = MINUTE_VALUES.reduce(function(best, minute) {
        return Math.abs(minute - wanted) < Math.abs(best - wanted) ? minute : best;
    }, 0);
    // Pick the option that really exists (its value may be "0" rather than "00").
    let match = Array.prototype.slice.call(selectEl.options).filter(function(opt) {
        return parseInt(opt.value, 10) === closest;
    })[0];
    selectEl.value = match ? match.value : MINUTE_OPTIONS[MINUTE_VALUES.indexOf(closest)];
}

/**
 * Fill date time row.
 *
 * @param {*} fields The fields.
 * @param {*} isoDatetime The iso datetime.
 */
function fillDateTimeRow(fields, isoDatetime) {
    let parts = isoDatetime.split('T');
    if (parts[0]) {
        let dateParts = parts[0].split('-'); // YYYY-MM-DD
        if (fields.yearSelect && dateParts[0]) {
            fields.yearSelect.value = String(parseInt(dateParts[0], 10));
        }
        if (fields.monthSelect && dateParts[1]) {
            fields.monthSelect.value = String(parseInt(dateParts[1], 10));
        }
        if (fields.daySelect && dateParts[2]) {
            fields.daySelect.value = String(parseInt(dateParts[2], 10));
        }
    }
    if (parts[1]) {
        let hm = parts[1].split(':');
        if (fields.hourSelect && hm[0]) {
            fields.hourSelect.value = hm[0];
        }
        if (fields.minuteSelect && hm[1]) {
            setMinuteSnapped(fields.minuteSelect, hm[1]);
        }
    }
}

/**
 * Apply prefill.
 *
 * @param {*} parts The parts.
 */
function applyPrefill(parts) {
    let data = editData;
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
        [parts.lockEnableCheckbox,
            parts.lockRow.daySelect, parts.lockRow.monthSelect, parts.lockRow.yearSelect,
            parts.lockRow.hourSelect, parts.lockRow.minuteSelect,
            parts.openEnableCheckbox,
            parts.openRow.daySelect, parts.openRow.monthSelect, parts.openRow.yearSelect,
            parts.openRow.hourSelect, parts.openRow.minuteSelect]
            .forEach(function(el) {
                if (el) {
                    el.disabled = true;
                }
            });
        let note = document.createElement('div');
        note.className = 'form-text text-muted';
        note.textContent = (langStrings && langStrings.readonly) ||
            'This discussion is already locked as of the time above. To change it, ' +
            'unlock the discussion manually first, then set a new schedule.';
        parts.body.appendChild(note);
        parts.fieldset.setAttribute('data-forumlock-readonly', '1');
    }
}

/**
 * Attach.
 */
function attach() {
    let form = document.querySelector('form[id^="mform"]') || document.querySelector('form.mform');
    if (!form) {
        return;
    }

    let anchor = findDisplayPeriodFieldset();
    nativeAnchorFieldset = anchor;

    let parts = buildFieldset();

    if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(parts.fieldset, anchor.nextSibling);
    } else {
        let submitRow = findSubmitRow(form);
        if (submitRow && submitRow.parentNode) {
            submitRow.parentNode.insertBefore(parts.fieldset, submitRow);
        } else {
            form.appendChild(parts.fieldset);
        }
    }

    applyPrefill(parts);

    let lockHidden = document.createElement('input');
    lockHidden.type = 'hidden';
    lockHidden.name = LOCK_FIELD_NAME;
    lockHidden.value = '0';
    form.appendChild(lockHidden);

    let openHidden = document.createElement('input');
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

        let lockTs = readDateTimeRow(parts.lockRow);
        lockHidden.value = String(lockTs);

        if (parts.openEnableCheckbox.checked) {
            let openTs = readDateTimeRow(parts.openRow);
            if (openTs > 0 && lockTs > 0 && openTs >= lockTs) {
                let strings = langStrings || {};
                // eslint-disable-next-line no-alert
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

/**
 * @param {Object} edit Prefill data for an existing root post's schedule.
 * @param {Object} strings Language strings for the section.
 */
export const init = (edit, strings) => {
    editData = edit;
    langStrings = strings;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attach);
    } else {
        attach();
    }
};
