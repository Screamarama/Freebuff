@props([
    'stateSales' => null,
    'countySales' => null,
    'citySales' => null,
    'vat' => null,
])

{{-- US tenants tax with state/county/city sales tax; everyone else with VAT.
     The server rejects both, this just hides the set that does not apply. --}}
<x-percent-input name="state_sales_tax" label="State sales tax" :value="$stateSales" class="sm:col-span-2" data-tax="sales" />
<x-percent-input name="county_sales_tax" label="County sales tax" :value="$countySales" class="sm:col-span-2" data-tax="sales" />
<x-percent-input name="city_sales_tax" label="City sales tax" :value="$citySales" class="sm:col-span-2" data-tax="sales" />
<x-percent-input name="vat" label="VAT" :value="$vat" class="sm:col-span-2" data-tax="vat" />

<script>
    (() => {
        const country = document.getElementById('country');

        if (!country) {
            return;
        }

        const sales = [...document.querySelectorAll('[data-tax="sales"]')];
        const vat = document.querySelector('[data-tax="vat"]');

        const apply = () => {
            const us = country.value === 'US';

            sales.forEach(field => field.hidden = ! us);
            vat.hidden = us;

            // Clear the hidden set so a value typed before the country changed
            // cannot be submitted alongside the other tax system.
            (us ? [vat] : sales).forEach(field => field.querySelector('input').value = '');
        };

        country.addEventListener('change', apply);
        apply();
    })();
</script>
