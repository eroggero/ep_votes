/**
 * Rende interattive le tabelle di voto generate da EPVotes_Post_Builder.
 * Vanilla JS, nessuna dipendenza esterna. Opera sugli attributi data-*
 * già presenti nel markup (niente logica di business qui: i dati sono
 * calcolati lato server al momento dell'importazione).
 */
(function () {
    'use strict';

    function initTable(wrap) {
        var rows = Array.prototype.slice.call(wrap.querySelectorAll('[data-epvotes-rows] tr'));
        var filters = {};

        var statsTotalEl = wrap.querySelector('[data-stats-total]');
        var statsCountEls = {};
        wrap.querySelectorAll('[data-stats-count]').forEach(function (el) {
            statsCountEls[el.getAttribute('data-stats-count')] = el;
        });

        var POSITION_TO_STAT = {
            FOR: 'for',
            AGAINST: 'against',
            ABSTENTION: 'abstention',
            DID_NOT_VOTE: 'absent'
        };

        function updateStats() {
            var visibleRows = rows.filter(function (row) {
                return !row.hasAttribute('data-epvotes-hidden');
            });

            var counts = { for: 0, against: 0, abstention: 0, absent: 0 };
            visibleRows.forEach(function (row) {
                var statKey = POSITION_TO_STAT[row.getAttribute('data-position')];
                if (statKey) {
                    counts[statKey]++;
                }
            });

            if (statsTotalEl) {
                statsTotalEl.textContent = visibleRows.length + ' deputati';
            }
            Object.keys(counts).forEach(function (key) {
                if (statsCountEls[key]) {
                    statsCountEls[key].textContent = counts[key];
                }
            });
        }

        function applyFilters() {
            rows.forEach(function (row) {
                var visible = true;

                if (filters.name && row.getAttribute('data-name').indexOf(filters.name) === -1) {
                    visible = false;
                }
                if (visible && filters.party && row.getAttribute('data-party') !== filters.party) {
                    visible = false;
                }
                if (visible && filters.group && row.getAttribute('data-group') !== filters.group) {
                    visible = false;
                }
                if (visible && filters.position && row.getAttribute('data-position') !== filters.position) {
                    visible = false;
                }
                if (visible && filters.rebel !== '' && filters.rebel !== undefined && row.getAttribute('data-rebel') !== filters.rebel) {
                    visible = false;
                }
                if (visible && filters.country && row.getAttribute('data-country') !== filters.country) {
                    visible = false;
                }

                if (visible) {
                    row.removeAttribute('data-epvotes-hidden');
                } else {
                    row.setAttribute('data-epvotes-hidden', '');
                }
            });

            updateStats();
        }

        // --- Menu personalizzato "Partito nazionale" (logo + ricerca + cascata sul Paese) ---
        var countrySelect = wrap.querySelector('[data-country-select]');
        var partyFilter = wrap.querySelector('[data-party-filter]');
        var partyOptions = partyFilter
            ? Array.prototype.slice.call(partyFilter.querySelectorAll('[data-party-option]'))
            : [];

        function updatePartyOptionsVisibility(countryCode, searchText) {
            var selectedValue = partyFilter.querySelector('input[data-filter-key="party"]').value;
            var selectionStillValid = false;

            partyOptions.forEach(function (option) {
                var optCountry = option.getAttribute('data-country');
                var matchesCountry = !countryCode || optCountry === '' || optCountry === countryCode;

                var label = (option.getAttribute('data-label') || '').toLowerCase();
                var matchesSearch = !searchText || label.indexOf(searchText) !== -1;

                var visible = matchesCountry && matchesSearch;
                if (visible) {
                    option.removeAttribute('data-epvotes-hidden');
                } else {
                    option.setAttribute('data-epvotes-hidden', '');
                }

                if (matchesCountry && option.getAttribute('data-value') === selectedValue) {
                    selectionStillValid = true;
                }
            });

            return selectionStillValid;
        }

        if (partyFilter) {
            var partyTrigger = partyFilter.querySelector('[data-party-trigger]');
            var partyTriggerLabel = partyFilter.querySelector('[data-party-trigger-label]');
            var partyHiddenInput = partyFilter.querySelector('input[data-filter-key="party"]');
            var partyPanel = partyFilter.querySelector('[data-party-panel]');
            var partySearch = partyFilter.querySelector('[data-party-search]');

            function selectParty(option) {
                partyHiddenInput.value = option.getAttribute('data-value') || '';
                partyTriggerLabel.textContent = option.getAttribute('data-label') || 'Tutti';
                filters.party = partyHiddenInput.value;
                applyFilters();
            }

            partyTrigger.addEventListener('click', function () {
                var isHidden = partyPanel.hidden;
                partyPanel.hidden = !isHidden;
                if (isHidden) {
                    partySearch.value = '';
                    updatePartyOptionsVisibility(countrySelect ? countrySelect.value : '', '');
                    partySearch.focus();
                }
            });

            document.addEventListener('click', function (e) {
                if (!partyFilter.contains(e.target)) {
                    partyPanel.hidden = true;
                }
            });

            partySearch.addEventListener('input', function () {
                updatePartyOptionsVisibility(
                    countrySelect ? countrySelect.value : '',
                    partySearch.value.trim().toLowerCase()
                );
            });

            partyOptions.forEach(function (option) {
                option.addEventListener('click', function () {
                    selectParty(option);
                    partyPanel.hidden = true;
                });
            });
        }

        if (countrySelect) {
            countrySelect.addEventListener('change', function () {
                var stillValid = updatePartyOptionsVisibility(countrySelect.value, '');
                // Se il partito selezionato non appartiene più al Paese
                // scelto, torniamo a "Tutti" invece di lasciare un filtro
                // incoerente.
                if (!stillValid && partyFilter) {
                    var allOption = partyFilter.querySelector('[data-party-option][data-value=""]');
                    if (allOption) {
                        partyFilter.querySelector('input[data-filter-key="party"]').value = '';
                        partyFilter.querySelector('[data-party-trigger-label]').textContent = 'Tutti';
                        filters.party = '';
                    }
                }
                applyFilters();
            });
        }

        var filterControls = wrap.querySelectorAll('[data-epvotes-filters] [data-filter-key]');
        filterControls.forEach(function (control) {
            var key = control.getAttribute('data-filter-key');
            control.addEventListener('input', function () {
                var value = control.value.trim();
                filters[key] = (control.tagName === 'SELECT') ? value : value.toLowerCase();
                applyFilters();
            });
            control.addEventListener('change', function () {
                var value = control.value.trim();
                filters[key] = (control.tagName === 'SELECT') ? value : value.toLowerCase();
                applyFilters();
            });
        });

        var tbody = wrap.querySelector('[data-epvotes-rows]');
        var headers = Array.prototype.slice.call(wrap.querySelectorAll('th[data-sortable]'));
        var currentSort = { key: null, direction: 1 };

        function rowSortValue(row, key) {
            switch (key) {
                case 'name':
                    return row.getAttribute('data-name') || '';
                case 'group':
                    return row.getAttribute('data-group') || '';
                case 'party':
                    return row.getAttribute('data-party') || '';
                case 'country':
                    return row.getAttribute('data-country') || '';
                case 'position':
                    return row.getAttribute('data-position') || '';
                case 'rebel':
                    return row.getAttribute('data-rebel') || '';
                default:
                    return '';
            }
        }

        headers.forEach(function (header) {
            header.addEventListener('click', function () {
                var key = header.getAttribute('data-sort-key');

                if (currentSort.key === key) {
                    currentSort.direction *= -1;
                } else {
                    currentSort.key = key;
                    currentSort.direction = 1;
                }

                headers.forEach(function (h) {
                    h.removeAttribute('data-sort-active');
                    h.removeAttribute('data-sort-direction');
                });
                header.setAttribute('data-sort-active', '');
                header.setAttribute('data-sort-direction', currentSort.direction === 1 ? '▲' : '▼');

                rows.sort(function (a, b) {
                    var va = rowSortValue(a, key);
                    var vb = rowSortValue(b, key);
                    if (va < vb) return -1 * currentSort.direction;
                    if (va > vb) return 1 * currentSort.direction;
                    return 0;
                });

                rows.forEach(function (row) {
                    tbody.appendChild(row);
                });
            });
        });

        updateStats();
    }

    function init() {
        var tables = document.querySelectorAll('[data-epvotes-table]');
        tables.forEach(initTable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
