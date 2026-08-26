(function () {
    'use strict';

    function applyFiltersFromForm(form, page) {
        var formData = new FormData(form);
        var params = new URLSearchParams();
        var targetPage = typeof page === 'number' && page > 0 ? page : 1;

        formData.forEach(function (value, key) {
            if (key === 'page') {
                return;
            }

            if (typeof value === 'string') {
                var trimmed = value.trim();

                if (trimmed !== '') {
                    params.set(key, trimmed);
                }
            } else if (value !== null && value !== undefined) {
                params.set(key, String(value));
            }
        });

        if (targetPage > 1) {
            params.set('page', String(targetPage));
        }

        var action = form.getAttribute('action') || window.location.pathname;
        var queryString = params.toString();

        window.location.href = queryString ? action + '?' + queryString : action;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('audit-log-filter-form');
        if (! form) {
            return;
        }

        var searchInput = document.getElementById('audit-log-search');
        var pagination = document.getElementById('audit-log-pagination');
        var clearButton = form.querySelector('[data-clear-filters]');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            applyFiltersFromForm(form, 1);
        });

        if (searchInput) {
            var debounceTimer = null;

            searchInput.addEventListener('input', function () {
                if (debounceTimer !== null) {
                    window.clearTimeout(debounceTimer);
                }

                debounceTimer = window.setTimeout(function () {
                    applyFiltersFromForm(form, 1);
                }, 400);
            });
        }

        if (pagination) {
            pagination.addEventListener('click', function (event) {
                var target = event.target;

                if (target instanceof Element) {
                    target = target.closest('[data-page]');
                } else {
                    target = null;
                }

                if (! target) {
                    return;
                }

                event.preventDefault();

                var pageAttribute = target.getAttribute('data-page');
                if (! pageAttribute) {
                    return;
                }

                var pageNumber = parseInt(pageAttribute, 10);
                if (Number.isNaN(pageNumber) || pageNumber < 1) {
                    return;
                }

                applyFiltersFromForm(form, pageNumber);
            });
        }

        if (clearButton instanceof HTMLElement) {
            clearButton.addEventListener('click', function () {
                var elements = Array.prototype.slice.call(form.elements);

                elements.forEach(function (element) {
                    if (! element || ! element.name || element.name === 'lang') {
                        return;
                    }

                    if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
                        if (element.type === 'checkbox' || element.type === 'radio') {
                            element.checked = false;
                        } else {
                            element.value = '';
                        }
                    } else if (element instanceof HTMLSelectElement) {
                        element.value = '';
                    }
                });

                applyFiltersFromForm(form, 1);
            });
        }
    });
})();
