function updateRateHint(row) {
    const product = row.querySelector('[data-product-select]');
    const rateSelect = row.querySelector('[data-rate-type]');
    const hint = row.querySelector('[data-rate-hint]');
    const price = row.querySelector('[data-unit-price]');

    if (!product || !hint || !price) {
        return;
    }

    const option = product.selectedOptions[0];
    const isRental = Boolean(rateSelect);

    if (!option || !option.value) {
        hint.textContent = isRental
            ? 'Select an item to see its list rate.'
            : 'Select an item to see its sale price.';
        price.placeholder = isRental ? 'List rate' : 'Sale price';
        return;
    }

    if (isRental) {
        const rate = option.dataset[rateSelect.value];
        hint.textContent = rate
            ? `List rate: $${rate} / ${rateSelect.value}`
            : 'No list rate for that block — enter an override price.';
        price.placeholder = rate ? `$${rate}` : 'Enter price';
    } else {
        const rate = option.dataset.sale;
        hint.textContent = rate ? `Sale price: $${rate}` : 'No sale price — enter an override price.';
        price.placeholder = rate ? `$${rate}` : 'Enter price';
    }
}

function dateValue(value) {
    if (!value) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);

    return year && month && day ? Date.UTC(year, month - 1, day) : null;
}

function agreementDates(row) {
    if (row.dataset.startDate && row.dataset.endDate) {
        return [row.dataset.startDate, row.dataset.endDate];
    }

    return [
        document.getElementById('start_date')?.value,
        document.getElementById('end_date')?.value,
    ];
}

function windowDays(start, end) {
    const from = dateValue(start);
    const to = dateValue(end);

    if (from === null || to === null || to < from) {
        return null;
    }

    return Math.max(1, Math.round((to - from) / 86400000) + 1);
}

function durationForWindow(days, rate) {
    if (rate === 'hour') {
        return days * 24;
    }
    if (rate === 'week') {
        return Math.ceil(days / 7);
    }
    if (rate === 'month') {
        return Math.ceil(days / 30);
    }

    return days;
}

function syncDuration(row) {
    const duration = row.querySelector('[data-duration]');

    if (!duration) {
        return;
    }

    const [start, end] = agreementDates(row);
    const days = windowDays(start, end);
    const rate = row.querySelector('[data-rate-type]')?.value ?? 'day';

    if (days === null) {
        duration.removeAttribute('max');
        return;
    }

    const max = durationForWindow(days, rate);
    duration.max = String(max);

    if (duration.dataset.durationAuto === '1') {
        duration.value = String(max);
    }
}

function syncAllDurations() {
    document.querySelectorAll('[data-item-row]').forEach(syncDuration);
}

function setStockHint(row, text, low = false) {
    const hint = row.querySelector('[data-stock-hint]');

    if (!hint) {
        return;
    }

    hint.textContent = text;
    hint.classList.toggle('text-red-600', low);
    hint.classList.toggle('text-gray-500', !low);
}

function quantityIn(row) {
    return Math.max(0, Number(row.querySelector('[data-quantity]')?.value) || 0);
}

/** Quantities of the same product in other unsaved rows of this form. */
function quantityForProduct(row, productId) {
    let total = 0;
    const form = row.closest('form');

    document.querySelectorAll('[data-item-row]').forEach((other) => {
        if (other.closest('form') !== form) {
            return;
        }

        if (other.querySelector('[data-product-select]')?.value === productId) {
            total += quantityIn(other);
        }
    });

    return total;
}

/** Quantities of the same product in other unsaved rows of this form. */
function quantityElsewhere(row, productId) {
    return quantityForProduct(row, productId) - quantityIn(row);
}

function renderAvailability(row) {
    const product = row.querySelector('[data-product-select]');
    const hint = row.querySelector('[data-stock-hint]');

    if (!product || !hint) {
        return;
    }

    if (!product.value) {
        setStockHint(row, row.dataset.itemKind === 'rental'
            ? 'Choose an item to check availability.'
            : 'Choose an item to check stock.');
        return;
    }

    if (row.dataset.availabilityError === '1') {
        return;
    }

    if (row.dataset.availabilityTracked === '0') {
        setStockHint(row, row.dataset.availabilityUnit === 'sale'
            ? 'Stock is not tracked for this item.'
            : 'Availability is not tracked for this item.');
        return;
    }

    if (row.dataset.availabilityTracked !== '1') {
        return;
    }

    const base = Math.max(0, Number(row.dataset.baseAvailable) || 0);
    const requested = quantityForProduct(row, product.value);
    const available = Math.max(0, base - quantityElsewhere(row, product.value));

    if (requested > base) {
        setStockHint(row, row.dataset.availabilityUnit === 'sale'
            ? `Only ${base} in stock — ${requested} requested on this form`
            : `Only ${base} available — ${requested} requested on this form`, true);
        return;
    }

    if (row.dataset.availabilityUnit === 'sale') {
        setStockHint(row, `${available} in stock`, available <= 0);
    } else {
        setStockHint(row, `${available} available for these dates`, available <= 0);
    }
}

function renderAllAvailability() {
    document.querySelectorAll('[data-item-row]').forEach(renderAvailability);
}

async function updateAvailability(row) {
    const product = row.querySelector('[data-product-select]');
    const hint = row.querySelector('[data-stock-hint]');
    const endpoint = row.dataset.availabilityUrl;

    if (!product || !hint || !endpoint) {
        return;
    }

    if (!product.value) {
        delete row.dataset.availabilityTracked;
        delete row.dataset.baseAvailable;
        delete row.dataset.availabilityError;
        renderAllAvailability();
        return;
    }

    const [start, end] = agreementDates(row);

    if (windowDays(start, end) === null) {
        delete row.dataset.availabilityTracked;
        delete row.dataset.baseAvailable;
        setStockHint(row, 'Choose valid agreement dates to check availability.');
        return;
    }

    const request = (Number(row.dataset.availabilitySeq) || 0) + 1;
    row.dataset.availabilitySeq = String(request);
    delete row.dataset.availabilityTracked;
    delete row.dataset.baseAvailable;
    delete row.dataset.availabilityError;
    setStockHint(row, 'Checking availability…');

    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('product_id', product.value);
    url.searchParams.set('start_date', start);
    url.searchParams.set('end_date', end);

    if (row.dataset.contractId) {
        url.searchParams.set('contract_id', row.dataset.contractId);
    }

    try {
        const response = await fetch(url, {
            headers: {Accept: 'application/json'},
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Availability request failed: ${response.status}`);
        }

        const data = await response.json();

        if (!row.isConnected || row.dataset.availabilitySeq !== String(request)) {
            return;
        }

        row.dataset.availabilityTracked = data.tracked ? '1' : '0';
        row.dataset.availabilityUnit = data.unit;
        row.dataset.baseAvailable = data.tracked ? String(data.available) : '';
        delete row.dataset.availabilityError;

        renderAllAvailability();
    } catch (error) {
        if (row.isConnected && row.dataset.availabilitySeq === String(request)) {
            row.dataset.availabilityError = '1';
            setStockHint(row, 'Availability could not be checked.', true);
        }
    }
}

function addRow(button) {
    const kind = button.dataset.addRow;
    const rows = document.getElementById(`${kind}-rows`);
    const template = document.getElementById(`${kind}-row-template`);

    if (!rows || !template) {
        return;
    }

    const index = Number(rows.dataset.nextIndex || 0);
    rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
    rows.dataset.nextIndex = String(index + 1);

    const row = rows.lastElementChild;
    syncDuration(row);
    updateRateHint(row);
    updateAvailability(row);
}

function removeRow(button) {
    const wrapper = button.closest('[data-item-row-wrapper]');

    if (!wrapper || !window.confirm('Remove this item? Entered values will be lost.')) {
        return;
    }

    wrapper.remove();
    renderAllAvailability();
}

function focusFirstError() {
    const first = document.querySelector('[data-invalid] [aria-invalid="true"]');

    if (first) {
        first.scrollIntoView({block: 'center'});
        first.focus({preventScroll: true});
    }
}

export function initAgreementItems() {
    document.querySelectorAll('[data-add-row]').forEach((button) => {
        button.addEventListener('click', () => addRow(button));
    });

    syncAllDurations();

    document.querySelectorAll('[data-item-row]').forEach((row) => {
        updateRateHint(row);
        updateAvailability(row);
    });

    document.addEventListener('input', (event) => {
        if (event.target.matches('[data-duration]')) {
            event.target.dataset.durationAuto = '0';
        }

        if (event.target.matches('[data-quantity]')) {
            renderAllAvailability();
        }
    });

    document.addEventListener('change', (event) => {
        const row = event.target.closest('[data-item-row]');

        if (!row) {
            return;
        }

        if (event.target.matches('select')) {
            syncDuration(row);
            updateRateHint(row);
            updateAvailability(row);
        }
    });

    ['start_date', 'end_date'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', () => {
            syncAllDurations();
            document.querySelectorAll('[data-item-row]').forEach(updateAvailability);
        });
    });

    document.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-row]');

        if (remove) {
            removeRow(remove);
        }
    });

    focusFirstError();
}
