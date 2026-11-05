// PBX -> Advanced configuration: which form fields apply, option help, recipes and the line diff.
(function () {
    var data = window.ADVANCED_CONFIG;
    var $ = function (id) { return document.getElementById(id); };
    var file = $('advancedFile');
    var target = $('advancedTarget');
    var type = $('advancedType');
    var name = $('advancedName');
    var options = $('advancedOptions');
    var labels = data.labels;

    function currentOptions() {
        if (target.value === 'raw') {
            return null;
        }
        if (file.value === 'pjsip') {
            return data.help.pjsip[type.value] || null;
        }
        return (data.help.files[file.value] || {})[name.value] || null;
    }

    function refreshHelper() {
        var list = currentOptions();
        var names = list ? Object.keys(list) : [];
        var box = $('advancedOptionNames');
        $('advancedHelper').style.display = names.length ? '' : 'none';
        box.textContent = '';
        names.forEach(function (n) {
            var o = document.createElement('option');
            o.value = n;
            o.label = list[n];
            box.appendChild(o);
        });
        $('advancedOptionHelp').textContent = (list && list[$('advancedOptionName').value]) || '';
    }

    function formChanged() {
        var isPjsip = file.value === 'pjsip';
        Array.prototype.forEach.call(target.options, function (o) {
            var offered = isPjsip || o.value === 'object' || o.value === 'raw';
            o.hidden = !offered;
            o.disabled = !offered;
        });
        if (target.options[target.selectedIndex].disabled) {
            target.value = 'object';
        }
        var t = target.value;
        var raw = t === 'raw';
        var named = !raw && (!isPjsip || t === 'object' || t === 'model' || t === 'new');
        $('advancedTypeGroup').style.display = isPjsip && !raw ? '' : 'none';
        $('advancedNameGroup').style.display = named ? '' : 'none';
        name.required = named;
        name.setAttribute('list', !isPjsip ? 'advancedSections-' + file.value : (t === 'model' ? 'advancedModels' : (t === 'new' ? '' : 'advancedObjects')));
        $('advancedNameLabel').textContent = labels[!isPjsip ? 'section' : (t === 'model' ? 'model' : 'name')];
        $('advancedOptionsLabel').textContent = raw ? labels.raw : labels.options;
        $('advancedOptionsHint').style.display = raw ? 'none' : '';
        $('advancedRawHint').style.display = raw ? '' : 'none';
        options.rows = raw ? 14 : 4;
        refreshHelper();
    }

    // Longest common subsequence of lines; the lists here are short.
    function lineDiff(before, after) {
        var a = before === '' ? [] : before.split('\n');
        var b = after === '' ? [] : after.split('\n');
        var i, j;
        var lcs = [];
        for (i = a.length; i >= 0; i--) {
            lcs[i] = [];
            for (j = b.length; j >= 0; j--) {
                lcs[i][j] = (i === a.length || j === b.length) ? 0
                    : (a[i] === b[j] ? lcs[i + 1][j + 1] + 1 : Math.max(lcs[i + 1][j], lcs[i][j + 1]));
            }
        }
        var out = [];
        i = 0;
        j = 0;
        while (i < a.length || j < b.length) {
            if (i < a.length && j < b.length && a[i] === b[j]) {
                i++;
                j++;
            } else if (j < b.length && (i === a.length || lcs[i][j + 1] >= lcs[i + 1][j])) {
                out.push('+ ' + b[j++]);
            } else {
                out.push('- ' + a[i++]);
            }
        }
        return out;
    }

    function refreshDiff() {
        var box = $('advancedDiff');
        if (!data.editing) {
            return;
        }
        var changes = lineDiff(options.dataset.saved.trim(), options.value.replace(/\r/g, '').trim());
        box.style.display = changes.length ? '' : 'none';
        box.textContent = changes.length ? labels.changes + '\n' + changes.join('\n') : '';
    }

    function applyRecipe() {
        var recipe = data.recipes[$('advancedRecipe').value];
        if (!recipe) {
            return;
        }
        file.value = recipe.config_file;
        target.value = recipe.target;
        if (recipe.object_type) {
            type.value = recipe.object_type;
        }
        name.value = recipe.object_name || '';
        options.value = recipe.options;
        formChanged();
    }

    file.addEventListener('change', formChanged);
    target.addEventListener('change', formChanged);
    type.addEventListener('change', refreshHelper);
    name.addEventListener('input', refreshHelper);
    options.addEventListener('input', refreshDiff);
    $('advancedOptionName').addEventListener('input', refreshHelper);
    $('advancedOptionAdd').addEventListener('click', function () {
        var field = $('advancedOptionName');
        if (field.value.trim() === '') {
            return;
        }
        options.value = options.value.replace(/\s*$/, '') + (options.value.trim() === '' ? '' : '\n') + field.value.trim() + '=';
        field.value = '';
        refreshHelper();
        refreshDiff();
        options.focus();
    });
    if ($('advancedRecipe')) {
        $('advancedRecipe').addEventListener('change', applyRecipe);
    }
    formChanged();
    refreshDiff();
})();
