    <script>
        (function () {
            const catalogue = @json($catalogue);

            const name = document.getElementById('name');
            const manufacturer = document.getElementById('manufacturer');
            const modelNumber = document.getElementById('model_number');
            const checkbox = document.getElementById('update_model_defaults');
            const checkboxWrap = document.getElementById('update-model-wrap');
            const notice = document.getElementById('model-match');
            const fields = document.getElementById('model-default-fields');

            const fieldsToToggle = [
                'rental_price_hour', 'rental_price_day', 'rental_price_week', 'rental_price_month',
                'deposit_amount', 'useful_life_months', 'salvage_value',
            ];

            // Mirrors ServiceHoldController::matchingModel(): name lowercased,
            // manufacturer and model number compared as entered.
            const key = (label, make, model) => [
                (label || '').trim().toLowerCase(),
                (make || '').trim(),
                (model || '').trim(),
            ].join('|');

            const index = new Map(catalogue.map((model) => [
                key(model.name, model.manufacturer, model.model_number),
                model,
            ]));

            let match = null;
            let lastKey = null;

            function setFieldsEnabled(enabled) {
                fields.classList.toggle('hidden', !enabled);

                fieldsToToggle.forEach((id) => {
                    const input = document.getElementById(id);
                    if (input) input.disabled = !enabled;
                });
            }

            // Ticking "update defaults" means "these values become the model's
            // defaults", so start from what the model has now, not an empty box.
            function fillFromMatch() {
                fieldsToToggle.forEach((id) => {
                    const input = document.getElementById(id);
                    if (input) input.value = match[id] ?? '';
                });
            }

            function apply(fillNow) {
                const currentKey = key(name.value, manufacturer.value, modelNumber.value);
                const changed = lastKey !== null && currentKey !== lastKey;

                lastKey = currentKey;
                match = index.get(currentKey) || null;

                if (!match) {
                    notice.textContent = notice.dataset.empty;
                    notice.className = 'rounded-md border px-3 py-2 text-sm border-gray-200 bg-gray-50 text-gray-600';
                    checkboxWrap.classList.add('hidden');
                    setFieldsEnabled(true);
                    return;
                }

                const update = checkbox.checked;

                notice.textContent = `${notice.dataset.joining} ${match.label} (${match.name}) — `
                    + (update ? notice.dataset.update : `${notice.dataset.keep} — ${match.summary}.`)
                    + (match.active ? '' : ` ${notice.dataset.retired}`);
                notice.className = 'rounded-md border px-3 py-2 text-sm border-indigo-200 bg-indigo-50 text-indigo-900';
                checkboxWrap.classList.remove('hidden');

                if (update && (fillNow || changed)) fillFromMatch();

                setFieldsEnabled(update);
            }

            [name, manufacturer, modelNumber].forEach((input) => input.addEventListener('input', () => apply(false)));

            checkbox.addEventListener('change', () => apply(true));

            // No fill on load: a validation bounce must keep the submitted values.
            apply(false);
        })();
    </script>
