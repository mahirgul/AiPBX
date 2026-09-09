/**
 * Universal Unified DataTables Enhancer Module (AI PBX)
 * Provides instant live search, column sorting, and smart pagination.
 */
(function() {
    'use strict';

    function initTableEnhancer(table) {
        if (!table || !(table instanceof HTMLElement)) return;
        
        // Skip tables explicitly opted out or already enhanced
        if (table.dataset.dtEnhanced === 'true' || 
            table.dataset.noDt === 'true' || 
            table.classList.contains('no-dt')) {
            return;
        }

        let tbody = table.querySelector('tbody');
        if (!tbody) return;

        // Skip permission matrix tables or tables with bulk checkbox inputs in thead
        if (table.querySelector('thead input[type="checkbox"]') || table.classList.contains('perm-matrix-table')) {
            return;
        }

        let thead = table.querySelector('thead');
        let headerCells = thead ? Array.from(thead.querySelectorAll('th')) : [];

        // Collect data rows (skip empty placeholder rows and no-match rows)
        let rows = Array.from(tbody.children).filter(row => {
            if (row.tagName !== 'TR') return false;
            if (row.classList.contains('empty-row') || row.classList.contains('dt-no-match-row')) return false;
            // uiTableEmptyRow() (config.php) her boş listede bu class'ı üretiyor —
            // isim burada yanlış yazılmıştı (".table-empty-container"), hiç
            // eşleşmiyordu; boş tablolarda tek "kayıt yok" satırı gerçek veri
            // gibi işlenip gereksiz arama/sayfalama çubuğu gösteriliyordu.
            if (row.querySelector('.empty-table-box')) return false;
            return true;
        });

        // Mark as enhanced to prevent duplicate processing
        table.dataset.dtEnhanced = 'true';

        // Prepare row data cache
        let allRowData = rows.map((row, index) => ({
            element: row,
            originalIndex: index,
            visible: true,
            text: row.textContent.toLowerCase().replace(/\s+/g, ' ')
        }));

        let currentSearchQuery = '';
        let currentSortCol = -1;
        let currentSortDir = 'asc';
        let pageSize = 25;
        let currentPage = 1;

        // Create Controls Bar (Search + Length)
        const controlsBar = document.createElement('div');
        controlsBar.className = 'dt-controls-bar';
        controlsBar.style.cssText = 'display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 12px; flex-wrap: wrap;';
        controlsBar.innerHTML = `
            <div class="dt-search-box" style="position: relative; flex: 1; max-width: 340px; min-width: 200px;">
                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; pointer-events: none;"></i>
                <input type="text" class="form-control dt-search-input" placeholder="Tabloda Arama Yap (Canlı Filtre)..." style="padding-left: 36px !important; height: 36px; font-size: 13px; border-radius: 8px; width: 100%;" value="">
            </div>
            <div class="dt-length-box" style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-muted); font-weight: 600;">
                <span>Göster:</span>
                <select class="form-control dt-length-select" style="height: 36px; padding: 4px 10px; font-size: 12.5px; border-radius: 8px; cursor: pointer;">
                    <option value="10">10 Kayıt</option>
                    <option value="25" selected>25 Kayıt</option>
                    <option value="50">50 Kayıt</option>
                    <option value="100">100 Kayıt</option>
                    <option value="-1">Tümü</option>
                </select>
            </div>
        `;

        // Insert Controls Bar directly above table
        const tableParent = table.parentNode;
        tableParent.insertBefore(controlsBar, table);

        // Create Pagination Bar directly below table
        const paginationBar = document.createElement('div');
        paginationBar.className = 'dt-pagination-bar';
        paginationBar.style.cssText = 'display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border-color); flex-wrap: wrap; font-size: 12.5px; color: var(--text-muted);';
        paginationBar.innerHTML = `
            <div class="dt-info" style="font-weight: 500;"></div>
            <div class="dt-pagination-nav" style="display: flex; align-items: center; gap: 4px;"></div>
        `;
        if (table.nextSibling) {
            tableParent.insertBefore(paginationBar, table.nextSibling);
        } else {
            tableParent.appendChild(paginationBar);
        }

        const searchInput = controlsBar.querySelector('.dt-search-input');
        const lengthSelect = controlsBar.querySelector('.dt-length-select');
        const infoEl = paginationBar.querySelector('.dt-info');
        const navEl = paginationBar.querySelector('.dt-pagination-nav');

        // Prevent Enter key inside search input from submitting any parent form
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
            }
        });

        // Setup Header Click Sorting
        headerCells.forEach((th, colIndex) => {
            const thText = th.textContent.trim().toLowerCase();
            const isActions = th.classList.contains('text-right') || thText === '' || thText === 'eylemler' || thText === 'eylem';
            if (isActions || th.classList.contains('no-sort')) return;

            th.classList.add('sortable');
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';

            if (!th.querySelector('.dt-sort-icon')) {
                const icon = document.createElement('i');
                icon.className = 'fas fa-sort dt-sort-icon';
                icon.style.cssText = 'margin-left: 6px; font-size: 11px; opacity: 0.4;';
                th.appendChild(icon);
            }

            th.addEventListener('click', function(e) {
                if (e.target.closest('input, button, a, select, form')) return;

                if (currentSortCol === colIndex) {
                    currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc';
                } else {
                    currentSortCol = colIndex;
                    currentSortDir = 'asc';
                }

                headerCells.forEach((h, i) => {
                    const icon = h.querySelector('.dt-sort-icon');
                    h.classList.remove('sort-asc', 'sort-desc');
                    if (icon) {
                        icon.className = 'fas fa-sort dt-sort-icon';
                        icon.style.opacity = '0.4';
                    }

                    if (i === currentSortCol) {
                        h.classList.add('sort-' + currentSortDir);
                        if (icon) {
                            icon.className = 'fas fa-sort-' + (currentSortDir === 'asc' ? 'up' : 'down') + ' dt-sort-icon';
                            icon.style.opacity = '1';
                            icon.style.color = 'var(--primary)';
                        }
                    }
                });

                sortRows();
                render();
            });
        });

        function getCellValue(rowEl, colIdx) {
            const cells = rowEl.children;
            if (!cells[colIdx]) return '';
            const text = cells[colIdx].textContent.trim();

            // Turkish date/datetime format used across the portal (d.m.Y or d.m.Y H:i:s).
            // Without this, dates get compared as plain strings (day-first), which sorts
            // "05.01.2026" before "17.08.2025" — wrong. Convert to a real timestamp instead.
            const dateMatch = text.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/);
            if (dateMatch) {
                const [, d, m, y, h, mi, s] = dateMatch;
                return new Date(+y, +m - 1, +d, +(h || 0), +(mi || 0), +(s || 0)).getTime();
            }

            const numClean = text.replace(/^#/, '').replace(/\s+/g, '').replace(/KB|MB|GB/i, '');
            if (numClean !== '' && !isNaN(numClean)) {
                return parseFloat(numClean);
            }
            return text;
        }

        function sortRows() {
            if (currentSortCol < 0) return;
            allRowData.sort((a, b) => {
                let valA = getCellValue(a.element, currentSortCol);
                let valB = getCellValue(b.element, currentSortCol);

                let comp = 0;
                if (typeof valA === 'number' && typeof valB === 'number') {
                    comp = valA - valB;
                } else {
                    comp = String(valA).localeCompare(String(valB), 'tr', { numeric: true, sensitivity: 'base' });
                }
                return (currentSortDir === 'asc') ? comp : -comp;
            });

            allRowData.forEach(item => tbody.appendChild(item.element));
        }

        function filterRows() {
            const query = currentSearchQuery.trim().toLowerCase();
            allRowData.forEach(item => {
                if (!query) {
                    item.visible = true;
                } else {
                    item.visible = item.text.includes(query);
                }
            });
        }

        function render() {
            filterRows();
            const visibleItems = allRowData.filter(item => item.visible);

            // Handle empty search result row
            let emptyMsgRow = tbody.querySelector('.dt-no-match-row');
            if (visibleItems.length === 0) {
                allRowData.forEach(item => item.element.style.display = 'none');
                if (!emptyMsgRow) {
                    emptyMsgRow = document.createElement('tr');
                    emptyMsgRow.className = 'dt-no-match-row';
                    const colSpan = headerCells.length || 6;
                    emptyMsgRow.innerHTML = `<td colspan="${colSpan}" style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px;"><i class="fas fa-search" style="margin-right: 6px; opacity: 0.6;"></i> Arama kriterlerine uygun kayıt bulunamadı.</td>`;
                    tbody.appendChild(emptyMsgRow);
                }
                emptyMsgRow.style.display = '';
                infoEl.innerHTML = 'Sonuç bulunamadı';
                navEl.innerHTML = '';
                return;
            } else if (emptyMsgRow) {
                emptyMsgRow.style.display = 'none';
            }

            const total = visibleItems.length;
            const size = (pageSize <= 0) ? total : pageSize;
            const totalPages = Math.max(1, Math.ceil(total / size));

            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const startIndex = (currentPage - 1) * size;
            const endIndex = Math.min(startIndex + size, total);

            // Hide all, show only active page items
            allRowData.forEach(item => item.element.style.display = 'none');
            for (let i = startIndex; i < endIndex; i++) {
                if (visibleItems[i]) visibleItems[i].element.style.display = '';
            }

            // Info Text
            if (currentSearchQuery.trim() !== '') {
                infoEl.innerHTML = `Toplam <strong>${allRowData.length}</strong> kayıttan süzülen <strong>${total}</strong> sonuç (${startIndex + 1} - ${endIndex} arası)`;
            } else {
                infoEl.innerHTML = `Toplam <strong>${total}</strong> kayıttan <strong>${startIndex + 1} - ${endIndex}</strong> arası gösteriliyor`;
            }

            // Render Pagination Buttons
            buildPaginationButtons(totalPages);
        }

        function buildPaginationButtons(totalPages) {
            navEl.innerHTML = '';
            if (totalPages <= 1) return;

            // Prev Button
            const prevBtn = document.createElement('button');
            prevBtn.className = 'dt-page-btn';
            prevBtn.type = 'button';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = function(e) {
                e.preventDefault();
                if (currentPage > 1) {
                    currentPage--;
                    render();
                }
            };
            navEl.appendChild(prevBtn);

            // Numbered Buttons
            const maxVisibleButtons = 5;
            let startP = Math.max(1, currentPage - 2);
            let endP = Math.min(totalPages, startP + maxVisibleButtons - 1);
            if (endP - startP < maxVisibleButtons - 1) {
                startP = Math.max(1, endP - maxVisibleButtons + 1);
            }

            if (startP > 1) {
                navEl.appendChild(createPageBtn(1));
                if (startP > 2) {
                    const dots = document.createElement('span');
                    dots.style.padding = '0 4px';
                    dots.textContent = '...';
                    navEl.appendChild(dots);
                }
            }

            for (let p = startP; p <= endP; p++) {
                navEl.appendChild(createPageBtn(p));
            }

            if (endP < totalPages) {
                if (endP < totalPages - 1) {
                    const dots = document.createElement('span');
                    dots.style.padding = '0 4px';
                    dots.textContent = '...';
                    navEl.appendChild(dots);
                }
                navEl.appendChild(createPageBtn(totalPages));
            }

            // Next Button
            const nextBtn = document.createElement('button');
            nextBtn.className = 'dt-page-btn';
            nextBtn.type = 'button';
            nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages);
            nextBtn.onclick = function(e) {
                e.preventDefault();
                if (currentPage < totalPages) {
                    currentPage++;
                    render();
                }
            };
            navEl.appendChild(nextBtn);
        }

        function createPageBtn(p) {
            const btn = document.createElement('button');
            btn.className = 'dt-page-btn' + (p === currentPage ? ' active' : '');
            btn.type = 'button';
            btn.textContent = p;
            btn.onclick = function(e) {
                e.preventDefault();
                currentPage = p;
                render();
            };
            return btn;
        }

        // Event Listeners
        searchInput.addEventListener('input', function() {
            currentSearchQuery = searchInput.value;
            currentPage = 1;
            render();
        });

        lengthSelect.addEventListener('change', function() {
            pageSize = parseInt(lengthSelect.value);
            currentPage = 1;
            render();
        });

        // Initial render
        render();
    }

    // Global Initializer Function
    window.enhanceAllDataTables = function() {
        const tables = document.querySelectorAll('table.data-table, table.portal-table');
        tables.forEach(table => initTableEnhancer(table));
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.enhanceAllDataTables);
    } else {
        window.enhanceAllDataTables();
    }
    document.addEventListener('spa:pageLoaded', window.enhanceAllDataTables);
})();
