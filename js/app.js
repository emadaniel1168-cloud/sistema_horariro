document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-spanish-validation]').forEach((form) => {
        const spanishMessage = (input) => {
            const validity = input.validity;
            if (validity.valueMissing) {
                return input.type === 'email'
                    ? 'Por favor, escribe tu correo electrónico.'
                    : 'Completa este campo.';
            }
            if (validity.typeMismatch && input.type === 'email') {
                return "Incluye un '@' en la dirección de correo electrónico.";
            }
            if (validity.tooShort) {
                return `Escribe al menos ${input.minLength} caracteres.`;
            }
            if (validity.tooLong) {
                return `Escribe como máximo ${input.maxLength} caracteres.`;
            }
            if (validity.patternMismatch) {
                return 'Revisa el formato de este campo.';
            }
            if (validity.badInput) {
                return 'Escribe un valor válido.';
            }
            return '';
        };

        const updateValidity = (input) => {
            input.setCustomValidity('');
            input.setCustomValidity(spanishMessage(input));
        };

        form.querySelectorAll('input, select, textarea').forEach((input) => {
            input.addEventListener('input', () => updateValidity(input));
            input.addEventListener('change', () => updateValidity(input));
        });

        form.addEventListener('submit', (event) => {
            form.querySelectorAll('input, select, textarea').forEach(updateValidity);
            if (!form.reportValidity()) {
                event.preventDefault();
            }
        });
    });

    const applyCancelObservation = (form) => {
        const stateSelect = form.querySelector('select[name="estado"]');
        const observationField = form.querySelector('textarea[name="observacion"]');
        const teacherSelect = form.querySelector('select[name="id_profesor"]');
        const subjectSelect = form.querySelector('select[name="id_materia"]');
        const roomSelect = form.querySelector('select[name="id_salon"]');
        const autoObservations = form.querySelector('[name="auto_observaciones"]');
        if (!stateSelect || !observationField) {
            return;
        }

        const originalTeacher = (form.dataset.originalTeacher || '').toString();
        const originalSubject = (form.dataset.originalSubject || '').toString();
        const originalRoom = (form.dataset.originalRoom || '').toString();

        const hasRealChange = () => {
            const teacherValue = teacherSelect?.value ?? '';
            const subjectValue = subjectSelect?.value ?? '';
            const roomValue = roomSelect?.value ?? '';
            return teacherValue !== originalTeacher || subjectValue !== originalSubject || roomValue !== originalRoom;
        };

        const hasStoredChangeNote = () => {
            return observationField.value.split(/\r?\n/).some((line) => {
                const trimmed = line.trim();
                return trimmed !== ''
                    && !trimmed.toLowerCase().includes('clase cancelada')
                    && /^(Profesor:|Materia:|Salón:|Observación:)/i.test(trimmed);
            });
        };

        const buildChangeNotes = () => {
            const notes = [];
            const teacherValue = teacherSelect?.value ?? '';
            const subjectValue = subjectSelect?.value ?? '';
            const roomValue = roomSelect?.value ?? '';
            const teacherName = teacherSelect?.selectedOptions[0]?.textContent?.trim() || 'Sin profesor';
            const subjectName = subjectSelect?.selectedOptions[0]?.textContent?.trim() || 'Sin materia';
            const roomName = roomSelect?.selectedOptions[0]?.textContent?.trim() || 'Sin salón';

            if (teacherValue && teacherValue !== originalTeacher) {
                notes.push(`Profesor: ${(form.dataset.originalTeacherName || originalTeacher || 'Sin profesor')}, ${teacherName}`);
            }
            if (subjectValue && subjectValue !== originalSubject) {
                notes.push(`Materia: ${(form.dataset.originalSubjectName || originalSubject || 'Sin materia')}, ${subjectName}`);
            }
            if (roomValue && roomValue !== originalRoom) {
                notes.push(`Salón: ${(form.dataset.originalRoomName || originalRoom || 'Sin salón')}, ${roomName}`);
            }
            return notes;
        };

        const setDefaultObservation = (trigger = 'init') => {
            if (autoObservations && !autoObservations.checked) return;
            const existingLines = observationField.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
            const cancelText = 'Clase cancelada desde el horario semanal.';
            const changeNotes = buildChangeNotes();
            const noteLines = existingLines.filter((line) => !line.toLowerCase().includes('clase cancelada'));
            const merged = [...new Set([...changeNotes, ...noteLines])];
            const statusValue = String(stateSelect.value || '').toUpperCase();

            if (statusValue === 'CANCELADA') {
                observationField.value = cancelText;
                return;
            }

            if (statusValue === 'PROGRAMADA') {
                const realChange = hasRealChange() || hasStoredChangeNote();
                if (trigger === 'state' && !realChange) {
                    observationField.value = '';
                    return;
                }
                if (realChange) {
                    observationField.value = merged.join('\n');
                    return;
                }
                if (trigger !== 'state' && existingLines.some((line) => line.toLowerCase().includes('clase cancelada'))) {
                    observationField.value = existingLines.join('\n');
                    return;
                }
                observationField.value = '';
            }
        };

        stateSelect.addEventListener('change', () => setDefaultObservation('state'));
        if (teacherSelect) {
            teacherSelect.addEventListener('change', () => {
                setDefaultObservation('field');
            });
        }
        [subjectSelect, roomSelect].forEach((select) => {
            if (select) {
                select.addEventListener('change', () => {
                    setDefaultObservation('field');
                });
            }
        });
        autoObservations?.addEventListener('change', () => {
            if (autoObservations.checked) setDefaultObservation('field');
        });
        setDefaultObservation('init');
    };

    document.querySelectorAll('form[data-class-editor], form[data-novelty-form]').forEach(applyCancelObservation);
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.parentElement?.querySelector('input');
            if (!(input instanceof HTMLInputElement)) return;
            const isVisible = input.type === 'text';
            input.type = isVisible ? 'password' : 'text';
            button.setAttribute('aria-pressed', String(!isVisible));
            const labels = {
                contrasena: 'contraseña',
                contrasena_actual: 'contraseña actual',
                contrasena_nueva: 'nueva contraseña',
                confirmar_contrasena: 'confirmación de contraseña',
            };
            button.setAttribute('aria-label', `${isVisible ? 'Mostrar' : 'Ocultar'} ${labels[input.id]}`);
        });
    });
    document.addEventListener('change', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.name === 'auto_observaciones') {
            const form = target.closest('form');
            const observation = form?.querySelector('[name="observacion"]');
            if (!observation) return;
            if (target.checked) {
                form._updateClassObservation?.();
            } else {
                form._removeAutomaticObservation?.();
            }
            return;
        }
        if (!(target instanceof HTMLSelectElement) || target.name !== 'estado') {
            return;
        }
        const form = target.closest('form');
        if (!form) {
            return;
        }
        const observationField = form.querySelector('textarea[name="observacion"]');
        const autoObservations = form.querySelector('[name="auto_observaciones"]');
        const teacherSelect = form.querySelector('select[name="id_profesor"]');
        const subjectSelect = form.querySelector('select[name="id_materia"]');
        const roomSelect = form.querySelector('select[name="id_salon"]');
        const originalTeacher = (form.dataset.originalTeacher || '').toString();
        const originalSubject = (form.dataset.originalSubject || '').toString();
        const originalRoom = (form.dataset.originalRoom || '').toString();
        const hasRealChange = (teacherSelect?.value ?? '') !== originalTeacher
            || (subjectSelect?.value ?? '') !== originalSubject
            || (roomSelect?.value ?? '') !== originalRoom;
        if (!observationField) {
            return;
        }
        if (autoObservations && !autoObservations.checked) return;
        if (form.matches('form[data-class-editor]')) {
            form._updateClassObservation?.();
            return;
        }
        const hasStoredChangeNote = () => {
            return observationField.value.split(/\r?\n/).some((line) => {
                const trimmed = line.trim();
                return trimmed !== ''
                    && !trimmed.toLowerCase().includes('clase cancelada')
                    && /^(Profesor:|Materia:|Salón:|Observación:)/i.test(trimmed);
            });
        };

        const buildChangeNotes = () => {
            const notes = [];
            const teacherValue = teacherSelect?.value ?? '';
            const subjectValue = subjectSelect?.value ?? '';
            const roomValue = roomSelect?.value ?? '';
            const teacherName = teacherSelect?.selectedOptions[0]?.textContent?.trim() || 'Sin profesor';
            const subjectName = subjectSelect?.selectedOptions[0]?.textContent?.trim() || 'Sin materia';
            const roomName = roomSelect?.selectedOptions[0]?.textContent?.trim() || 'Sin salón';

            if (teacherValue && teacherValue !== originalTeacher) {
                notes.push(`Profesor: ${(form.dataset.originalTeacherName || originalTeacher || 'Sin profesor')}, ${teacherName}`);
            }
            if (subjectValue && subjectValue !== originalSubject) {
                notes.push(`Materia: ${(form.dataset.originalSubjectName || originalSubject || 'Sin materia')}, ${subjectName}`);
            }
            if (roomValue && roomValue !== originalRoom) {
                notes.push(`Salón: ${(form.dataset.originalRoomName || originalRoom || 'Sin salón')}, ${roomName}`);
            }
            return notes;
        };

        const statusValue = String(target.value || '').toUpperCase();
        if (statusValue === 'CANCELADA') {
            const cancelText = 'Clase cancelada desde el horario semanal.';
            observationField.value = cancelText;
        }
        if (statusValue === 'PROGRAMADA') {
            const lines = observationField.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
            const notes = [...new Set([...buildChangeNotes(), ...lines.filter((line) => !line.toLowerCase().includes('clase cancelada'))])];
            if (!hasRealChange && !hasStoredChangeNote()) {
                observationField.value = '';
            } else {
                observationField.value = notes.join('\n');
            }
        }
    });

    const setupGradeDuplicateCheck = (form) => {
        const input = form.querySelector('[name="id_grado"]');
        const message = form.querySelector('[data-grade-duplicate-message]');
        if (!input || !message) return;

        let existingGrades = [];
        try {
            existingGrades = JSON.parse(form.dataset.existingGrades || '[]')
                .map((grade) => String(grade).trim().toLocaleUpperCase());
        } catch (error) {
            existingGrades = [];
        }

        const validate = () => {
            const grade = input.value.trim().toLocaleUpperCase();
            const original = form.querySelector('[name="id_original"]')?.value.trim().toLocaleUpperCase() || '';
            const duplicate = grade !== '' && grade !== original && existingGrades.includes(grade);
            const text = duplicate ? `El grado ${grade} ya fue creado.` : '';
            input.setCustomValidity(text);
            input.setAttribute('aria-invalid', String(duplicate));
            message.textContent = text;
            message.hidden = !duplicate;
        };

        input.addEventListener('input', validate);
        input.addEventListener('change', validate);
    };

    const searchableSelectSelector = 'select[name*="profesor"]:not([name^="orden_"]), select[data-change-teacher], select[data-filter-teacher], select[name*="materia"]:not([name^="orden_"]), select[data-change-subject], select[data-filter-subject], select[data-searchable-subject], select[data-searchable-room]';
    let searchableSelectId = 0;
    let searchableSelectionInProgress = false;
    const addSearchableSelect = (select) => {
        if (select.dataset.searchableSelectReady || select.closest('[hidden]')) return;
        select.dataset.searchableSelectReady = '1';
        searchableSelectId += 1;

        const wrapper = document.createElement('div');
        const search = document.createElement('input');
        const list = document.createElement('div');
        const listId = `select-search-options-${searchableSelectId}`;
        const label = select.closest('.field')?.querySelector('label')?.textContent.trim() || 'profesor';
        const resource = select.matches('select[name*="materia"], select[data-change-subject], select[data-filter-subject], select[data-searchable-subject]')
            ? 'materia'
            : select.matches('select[name*="salon"], select[data-searchable-room]') ? 'salon' : 'profesor';
        const resourceLabel = resource === 'salon' ? 'salón' : resource;
        let filteredOptions = [];
        let activeIndex = -1;
        search.type = 'search';
        search.className = 'select-search-input';
        search.placeholder = `Escriba para buscar ${resourceLabel}`;
        search.setAttribute('aria-label', `Buscar ${label.toLowerCase()}`);
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-controls', listId);
        search.setAttribute('aria-expanded', 'false');
        search.autocomplete = 'off';
        wrapper.className = 'select-search';
        list.id = listId;
        list.className = 'select-search-options';
        list.setAttribute('role', 'listbox');
        list.hidden = true;

        const closeOptions = () => {
            list.hidden = true;
            search.setAttribute('aria-expanded', 'false');
            search.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        };
        const positionOptions = () => {
            const bounds = search.getBoundingClientRect();
            const viewportPadding = 8;
            const maxMenuHeight = Math.min(window.innerHeight * 0.45, 320);
            const spaceBelow = Math.max(0, window.innerHeight - bounds.bottom - viewportPadding);
            const spaceAbove = Math.max(0, bounds.top - viewportPadding);
            const menuHeight = Math.min(list.scrollHeight, maxMenuHeight);
            const opensUp = spaceBelow < menuHeight && spaceAbove > spaceBelow;
            const availableSpace = opensUp ? spaceAbove : spaceBelow;
            const width = Math.min(bounds.width, window.innerWidth - viewportPadding * 2);

            list.style.left = `${Math.max(viewportPadding, Math.min(bounds.left, window.innerWidth - width - viewportPadding))}px`;
            list.style.width = `${width}px`;
            list.style.maxHeight = `${Math.max(80, Math.min(maxMenuHeight, availableSpace))}px`;
            list.style.top = opensUp ? 'auto' : `${bounds.bottom - 4}px`;
            list.style.bottom = opensUp ? `${window.innerHeight - bounds.top - 4}px` : 'auto';
        };
        const refreshOptions = (showList = !list.hidden) => {
            const query = search.value.trim().toLocaleLowerCase();
            const matchingOptions = Array.from(select.options).filter((option) =>
                option.value !== '' && option.value !== '0' && !option.hidden
                    && option.textContent.trim().toLocaleLowerCase().includes(query)
            );
            const uniqueOptions = new Map();
            for (const option of matchingOptions) {
                const group = option.dataset.searchGroup || option.closest('optgroup')?.label || '';
                const key = `${group}\0${option.textContent.trim().toLocaleLowerCase()}`;
                if (!uniqueOptions.has(key) || option.value === select.value) {
                    uniqueOptions.set(key, {
                        option,
                        group,
                    });
                }
            }
            const options = Array.from(uniqueOptions.values());
            filteredOptions = [];
            activeIndex = -1;
            list.replaceChildren();
            const appendGroupHeading = (group) => {
                if (!group) return;
                const heading = document.createElement('div');
                heading.className = 'select-search-group';
                heading.setAttribute('role', 'presentation');
                heading.textContent = group;
                list.append(heading);
            };
            const appendSuggestion = (option) => {
                const index = filteredOptions.length;
                filteredOptions.push(option);
                const suggestion = document.createElement('button');
                suggestion.type = 'button';
                suggestion.className = 'select-search-option';
                suggestion.id = `${listId}-option-${index}`;
                suggestion.setAttribute('role', 'option');
                suggestion.setAttribute('aria-selected', 'false');
                suggestion.textContent = option.textContent.trim();
                suggestion.disabled = option.disabled;
                if (option.dataset.disabledReason) suggestion.title = option.dataset.disabledReason;
                suggestion.addEventListener('mousedown', (event) => event.preventDefault());
                suggestion.addEventListener('click', () => {
                    if (!option.disabled) chooseOption(option);
                });
                list.append(suggestion);
            };
            const searchGroups = JSON.parse(select.dataset.searchGroups || '[]');
            if (!query && searchGroups.length) {
                searchGroups.forEach((group) => {
                    const groupOptions = options.filter((entry) => entry.group === group);
                    if (!groupOptions.length) return;
                    appendGroupHeading(group);
                    groupOptions.forEach(({option}) => appendSuggestion(option));
                });
            } else {
                let previousGroup = '';
                options.forEach(({option, group}) => {
                if (group && group !== previousGroup) {
                    appendGroupHeading(group);
                    previousGroup = group;
                }
                    appendSuggestion(option);
                });
            }
            if (!options.length && query) {
                const empty = document.createElement('div');
                empty.className = 'select-search-empty';
                empty.textContent = 'No hay opciones que coincidan.';
                list.append(empty);
            }
            list.hidden = !showList || (!options.length && !query);
            if (!list.hidden) {
                positionOptions();
            }
            search.setAttribute('aria-expanded', String(!list.hidden));
        };
        const syncSearch = () => {
            const selected = select.selectedOptions[0];
            search.value = selected && selected.value !== '' && selected.value !== '0' ? selected.textContent.trim() : '';
            search.disabled = select.disabled;
            search.required = select.required || select.dataset.searchableSelectRequired === '1';
            search.setCustomValidity('');
            refreshOptions(false);
        };
        const chooseOption = (option) => {
            if (option.disabled || option.hidden) return;
            search.value = option.textContent.trim();
            searchableSelectionInProgress = true;
            select._searchSelectionInProgress = true;
            select.value = option.value;
            search.setCustomValidity('');
            select.dispatchEvent(new Event('change', {bubbles: true}));
            select._searchSelectionInProgress = false;
            searchableSelectionInProgress = false;
            refreshOptions(false);
        };
        const setActiveOption = (index) => {
            const enabledIndexes = filteredOptions.reduce((indexes, option, optionIndex) => {
                if (!option.disabled && !option.hidden) indexes.push(optionIndex);
                return indexes;
            }, []);
            if (!enabledIndexes.length) {
                activeIndex = -1;
                search.removeAttribute('aria-activedescendant');
                return;
            }
            const currentPosition = enabledIndexes.indexOf(activeIndex);
            const direction = index >= activeIndex ? 1 : -1;
            const nextPosition = currentPosition < 0
                ? (direction > 0 ? 0 : enabledIndexes.length - 1)
                : (currentPosition + direction + enabledIndexes.length) % enabledIndexes.length;
            activeIndex = enabledIndexes[nextPosition];
            list.querySelectorAll('.select-search-option').forEach((option, optionIndex) => {
                const active = optionIndex === activeIndex;
                option.setAttribute('aria-selected', String(active));
                option.classList.toggle('is-active', active);
            });
            search.setAttribute('aria-activedescendant', `${listId}-option-${activeIndex}`);
            list.querySelector(`#${CSS.escape(`${listId}-option-${activeIndex}`)}`)?.scrollIntoView({block: 'nearest'});
        };
        select._syncSearchableSelect = syncSearch;

        const prepareSearch = () => {
            const selected = select.selectedOptions[0];
            const selectedLabel = selected?.value && selected.value !== '0' ? selected.textContent.trim() : '';
            if (selectedLabel && search.value.trim().toLocaleLowerCase() === selectedLabel.toLocaleLowerCase()) {
                const placeholder = Array.from(select.options).find((option) => option.value === '')
                    || Array.from(select.options).find((option) => option.value === '0');
                searchableSelectionInProgress = true;
                select._searchSelectionInProgress = true;
                select._searchClearing = true;
                select.value = placeholder?.value ?? '';
                select.dispatchEvent(new Event('change', {bubbles: true}));
                select._searchClearing = false;
                select._searchSelectionInProgress = false;
                searchableSelectionInProgress = false;
            }
            search.value = '';
            search.setCustomValidity('');
            refreshOptions(true);
        };

        search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            const selected = query
                ? Array.from(select.options).find((option) =>
                    option.value !== '' && option.value !== '0' && !option.disabled && !option.hidden
                    && option.textContent.trim().toLocaleLowerCase() === query
                )
                : null;
            if (selected) {
                chooseOption(selected);
                return;
            }

            const placeholder = Array.from(select.options).find((option) => option.value === '')
                || Array.from(select.options).find((option) => option.value === '0');
            const nextValue = placeholder?.value ?? '';
            if (select.value !== nextValue) {
                searchableSelectionInProgress = true;
                select._searchSelectionInProgress = true;
                select.value = nextValue;
                select.dispatchEvent(new Event('change', {bubbles: true}));
                select._searchSelectionInProgress = false;
                searchableSelectionInProgress = false;
            }
            const articulo = resource === 'materia' ? 'una' : 'un';
            search.setCustomValidity(query ? `Seleccione ${articulo} ${resource} de las sugerencias.` : '');
            refreshOptions(true);
        });
        search.addEventListener('focus', prepareSearch);
        search.addEventListener('pointerdown', prepareSearch);
        search.addEventListener('blur', () => window.setTimeout(closeOptions, 100));
        search.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' && !list.hidden) {
                event.preventDefault();
                setActiveOption(activeIndex + 1);
            } else if (event.key === 'ArrowUp' && !list.hidden) {
                event.preventDefault();
                setActiveOption(activeIndex - 1);
            } else if (event.key === 'Enter' && !list.hidden && activeIndex >= 0) {
                event.preventDefault();
                chooseOption(filteredOptions[activeIndex]);
            } else if (event.key === 'Escape') {
                closeOptions();
            }
        });
        select.addEventListener('change', () => {
            if (!select._searchSelectionInProgress) syncSearch();
            refreshOptions();
        });

        const wasRequired = select.required;
        select.required = false;
        if (wasRequired) select.dataset.searchableSelectRequired = '1';
        select.classList.add('searchable-select-native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        wrapper.append(search);
        select.parentNode.insertBefore(wrapper, select);
        list._positionOptions = positionOptions;
        list._searchInput = search;
        document.body.append(list);
        refreshOptions(false);
        syncSearch();

        new MutationObserver((records) => {
            refreshOptions();
            if (records.some((record) => record.target === select && record.attributeName === 'disabled')) {
                search.disabled = select.disabled;
            }
        }).observe(select, {
            attributes: true,
            attributeFilter: ['disabled', 'hidden'],
            childList: true,
            subtree: true,
        });
    };
    const repositionOpenSearchOptions = () => {
        document.querySelectorAll('.select-search-options:not([hidden])').forEach((list) => list._positionOptions?.());
    };
    document.addEventListener('scroll', repositionOpenSearchOptions, true);
    window.addEventListener('resize', repositionOpenSearchOptions);
    const setupSearchableSelects = (root) => {
        if (root instanceof HTMLSelectElement && root.matches(searchableSelectSelector)) {
            addSearchableSelect(root);
        }
        root.querySelectorAll?.(searchableSelectSelector).forEach(addSearchableSelect);
    };
    setupSearchableSelects(document);
    new MutationObserver((records) => {
        document.querySelectorAll('.select-search-options').forEach((list) => {
            if (!list._searchInput?.isConnected) list.remove();
        });
        for (const record of records) {
            record.addedNodes.forEach((node) => {
                if (node instanceof Element) setupSearchableSelects(node);
            });
            if (record.target instanceof HTMLOptionElement) {
                setupSearchableSelects(record.target.parentElement);
            }
        }
    }).observe(document.body, {childList: true, subtree: true});
    document.addEventListener('change', () => {
        if (searchableSelectionInProgress) return;
        window.setTimeout(() => {
            document.querySelectorAll('select.searchable-select-native').forEach((select) => select._syncSearchableSelect?.());
        }, 0);
    });

    document.querySelectorAll('[data-toggle-schedule-filters]').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = document.getElementById(button.dataset.toggleScheduleFilters);
            if (!panel) return;
            panel.hidden = !panel.hidden;
            button.setAttribute('aria-expanded', String(!panel.hidden));
        });
    });
    const expiringItems = Array.from(document.querySelectorAll('[data-expire-at]'));
    if (expiringItems.length) {
        const nextExpiry = Math.min(...expiringItems.map((item) => Number(item.dataset.expireAt)).filter(Number.isFinite));
        if (Number.isFinite(nextExpiry)) {
            window.setTimeout(() => window.location.reload(), Math.max(0, nextExpiry - Date.now()));
        }
    }

    const schedulePage = window.location.pathname.endsWith('/admin/horarios.php');
    const scheduleGradeSelect = schedulePage ? document.querySelector('.toolbar select[name="grado"]') : null;
    const noveltySchedulePage = window.location.pathname.endsWith('/admin/horario_novedades.php');
    const noveltyGradeSelect = noveltySchedulePage ? document.querySelector('#novelty-grade') : null;
    const gradeSelect = scheduleGradeSelect || noveltyGradeSelect;
    const scheduleGradeKey = 'colegio-santander-horario-grado';
    let savedScheduleGrade = '';
    try {
        savedScheduleGrade = window.localStorage.getItem(scheduleGradeKey) || '';
        if (gradeSelect) {
            const url = new URL(window.location.href);
            if (url.searchParams.has('grado')) {
                if (gradeSelect.value) {
                    savedScheduleGrade = gradeSelect.value;
                    window.localStorage.setItem(scheduleGradeKey, savedScheduleGrade);
                } else {
                    savedScheduleGrade = '';
                    window.localStorage.removeItem(scheduleGradeKey);
                }
            } else if (savedScheduleGrade && Array.from(gradeSelect.options).some((option) => option.value === savedScheduleGrade)) {
                url.searchParams.set('grado', savedScheduleGrade);
                window.location.replace(url.toString());
                return;
            }
        }
        if (savedScheduleGrade) {
            const scheduleLink = document.querySelector('.nav-submenu a[href$="/admin/horarios.php"]');
            if (scheduleLink) {
                const url = new URL(scheduleLink.href);
                url.searchParams.set('grado', savedScheduleGrade);
                scheduleLink.href = url.toString();
            }
            const noveltyScheduleLink = document.querySelector('.nav-submenu a[href$="/admin/horario_novedades.php"]');
            if (noveltyScheduleLink) {
                const url = new URL(noveltyScheduleLink.href);
                url.searchParams.set('grado', savedScheduleGrade);
                noveltyScheduleLink.href = url.toString();
            }
        }
    } catch (error) {
        savedScheduleGrade = '';
    }
    if (gradeSelect) {
        document.addEventListener('change', (event) => {
            if (event.target !== gradeSelect) return;
            try {
                if (gradeSelect.value) {
                    window.localStorage.setItem(scheduleGradeKey, gradeSelect.value);
                } else {
                    window.localStorage.removeItem(scheduleGradeKey);
                }
            } catch (error) {
                // Storage can be unavailable in restricted browser contexts.
            }
        }, true);
    }
    const modal = document.getElementById('modal');
    const title = document.getElementById('modal-title');
    const body = document.getElementById('modal-body');
    const weekdayFromDate = (value) => {
        if (!value) return '';
        const [year, month, day] = value.split('-').map(Number);
        if (!year || !month || !day) return '';
        return String(new Date(Date.UTC(year, month - 1, day)).getUTCDay() || 7);
    };
    const showConfirmDialog = (heading, message, acceptText = 'Aceptar', cancelText = 'Cancelar') => new Promise((resolve) => {
        const previousFocus = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';

        const dialog = document.createElement('section');
        dialog.className = 'confirm-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', heading);

        const title = document.createElement('h2');
        title.textContent = heading;
        const description = document.createElement('p');
        description.textContent = message;
        const actions = document.createElement('div');
        actions.className = 'confirm-actions';

        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'btn btn-secondary';
        cancelButton.textContent = cancelText;
        const acceptButton = document.createElement('button');
        acceptButton.type = 'button';
        acceptButton.className = 'btn btn-primary';
        acceptButton.textContent = acceptText;

        const close = (confirmed) => {
            document.removeEventListener('keydown', handleKeydown);
            overlay.remove();
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
            resolve(confirmed);
        };
        const handleKeydown = (event) => {
            if (event.key === 'Escape') close(false);
        };

        cancelButton.addEventListener('click', () => close(false));
        acceptButton.addEventListener('click', () => close(true));
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close(false);
        });
        actions.append(cancelButton, acceptButton);
        dialog.append(title, description, actions);
        overlay.append(dialog);
        document.body.append(overlay);
        document.addEventListener('keydown', handleKeydown);
        acceptButton.focus();
    });

    const showAlertDialog = (heading, message) => new Promise((resolve) => {
        const previousFocus = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';

        const dialog = document.createElement('section');
        dialog.className = 'confirm-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', heading);

        const title = document.createElement('h2');
        title.textContent = heading;
        const description = document.createElement('p');
        description.textContent = message;
        const actions = document.createElement('div');
        actions.className = 'confirm-actions';

        const okButton = document.createElement('button');
        okButton.type = 'button';
        okButton.className = 'btn btn-primary';
        okButton.textContent = 'Aceptar';

        const close = () => {
            document.removeEventListener('keydown', handleKeydown);
            overlay.remove();
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
            resolve();
        };
        const handleKeydown = (event) => {
            if (event.key === 'Escape' || event.key === 'Enter') close();
        };

        okButton.addEventListener('click', close);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
        actions.append(okButton);
        dialog.append(title, description, actions);
        overlay.append(dialog);
        document.body.append(overlay);
        document.addEventListener('keydown', handleKeydown);
        okButton.focus();
    });

    const popupAlert = document.querySelector('[data-popup-alert]');
    if (popupAlert) {
        showAlertDialog(popupAlert.dataset.popupTitle || 'Aviso', popupAlert.dataset.popupAlert || 'Ocurrió un problema con el horario.');
    }

    const updateRoomAvailability = async (form) => {
        const status = form.querySelector('.availability-status');
        const roomSelect = form.querySelector('select[name="id_salon"], select[name="nuevo_salon"]');
        const teacherSelect = form.querySelector('select[name="profesor_reemplazo"], select[name="nuevo_profesor"], select[name="id_profesor"]');
        if (!status || (!roomSelect && !teacherSelect)) return;

        const params = new URLSearchParams();
        const noveltyType = form.querySelector('[name="tipo_novedad"]');
        if (noveltyType) {
            const date = form.querySelector('[name="fecha"]')?.value;
            const scheduleId = form.querySelector('[name="id_horario"]')?.value;
            if (!date || !scheduleId) {
                status.textContent = 'Seleccione fecha y clase para consultar salones disponibles.';
                return;
            }
            params.set('fecha', date);
            params.set('id_horario', scheduleId);
            params.set('tipo_novedad', noveltyType.value);
            if (noveltyType.value === 'CAMBIO_HORA') {
                params.set('hora_inicio', form.querySelector('[name="nueva_hora_inicio"]')?.value || '');
                params.set('hora_fin', form.querySelector('[name="nueva_hora_fin"]')?.value || '');
            }
            const teacher = form.querySelector('[name="nuevo_profesor"]')?.value;
            if (teacher) params.set('nuevo_profesor', teacher);
        } else {
            const day = form.querySelector('[name="id_dia"]')?.value;
            const block = form.querySelector('[name="id_bloque"]')?.value;
            if (!day || !block) {
                status.textContent = 'Seleccione día y hora para consultar salones disponibles.';
                return;
            }
            params.set('dia', day);
            params.set('id_bloque', block);
            params.set('id_horario', form.querySelector('[name="id_horario"]')?.value || '');
            const date = form.querySelector('[name="fecha"]')?.value;
            if (date) params.set('fecha', date);
            const additionId = form.querySelector('[name="id_novedad"]')?.value;
            if (additionId) params.set('ignorar_adicion', additionId);
            const teacher = form.querySelector('[name="profesor_reemplazo"]')?.value
                || form.querySelector('[name="nuevo_profesor"]')?.value
                || form.querySelector('[name="id_profesor"]')?.value;
            if (teacher) params.set('id_profesor', teacher);
        }

        status.textContent = 'Consultando disponibilidad...';
        try {
            const response = await fetch(`${form.dataset.availabilityUrl}&${params.toString()}`);
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'No se pudo consultar la disponibilidad.');

            const availableRooms = result.salones.filter((room) => room.disponible);
            if (roomSelect) {
                const roomById = new Map(result.salones.map((room) => [String(room.id), room]));
                for (const option of roomSelect.options) {
                    if (!option.value) continue;
                    const room = roomById.get(option.value);
                    if (!room) continue;
                    option.dataset.roomName ||= room.nombre;
                    option.disabled = !room.disponible && option.value !== roomSelect.value;
                    option.textContent = `${option.dataset.roomName} · ${room.disponible ? 'Libre' : 'Ocupado'}`;
                }
            }

            const availableTeachers = result.profesores.filter((teacher) => teacher.disponible);
            const teachersById = new Map(result.profesores.map((teacher) => [String(teacher.id), teacher]));
            if (teacherSelect) {
                for (const option of teacherSelect.options) {
                    if (!option.value) continue;
                    const teacher = teachersById.get(option.value);
                    if (!teacher) continue;
                    option.dataset.teacherName ||= option.textContent.replace(/\s+·\s+(Disponible|Ocupado.*)$/, '');
                    option.disabled = !teacher.disponible && option.value !== teacherSelect.value;
                    option.hidden = !teacher.disponible && option.value !== teacherSelect.value;
                    option.textContent = teacher.disponible
                        ? `${option.dataset.teacherName} · Disponible`
                        : `${option.dataset.teacherName} · Ocupado${teacher.conflicto ? ` (${teacher.conflicto})` : ''}`;
                }
                teacherSelect._syncSearchableSelect?.();
            }

            const messages = [
                ...(roomSelect ? [availableRooms.length
                    ? `${availableRooms.length} salones libres para este bloque.`
                    : 'No hay salones libres para este bloque.'] : []),
                ...(teacherSelect ? [`${availableTeachers.length} profesor(es) disponible(s) para reemplazar en este horario.`] : []),
            ];
            if (result.conflicto_profesor) messages.push(result.conflicto_profesor);
            status.textContent = messages.join(' ');
            status.dataset.state = (!roomSelect || availableRooms.length) && (!teacherSelect || availableTeachers.length)
                ? 'available'
                : 'unavailable';
        } catch (error) {
            status.textContent = error.message;
            status.dataset.state = 'unavailable';
        }
    };

    const setupRoomCreateEditor = (form) => {
        const gradeSelect = form.querySelector('[name="id_grado"]');
        const blockInput = form.querySelector('[name="id_bloque"]');
        const teacherSelect = form.querySelector('[name="id_profesor"]');
        const subjectSelect = form.querySelector('[name="id_materia"]');
        const status = form.querySelector('[data-room-create-status]');
        if (!gradeSelect || !blockInput || !teacherSelect || !subjectSelect || !status) return;

        const teacherOptions = Array.from(teacherSelect.options).filter((option) => option.value);
        let requestVersion = 0;
        teacherOptions.forEach((option) => {
            option.hidden = true;
            option.disabled = true;
        });

        const selectMostUsedSubject = () => {
            const teacherOption = teacherSelect.selectedOptions[0];
            if (!teacherOption?.value) return;
            const counts = new Map((teacherOption.dataset.subjectCounts || '')
                .split(',')
                .filter(Boolean)
                .map((item) => item.split(':'))
                .map(([id, count]) => [id, Number(count)]));
            const subjects = new Set((teacherOption.dataset.subjects || '').split(',').filter(Boolean));
            const permanentSubjects = new Set((teacherOption.dataset.permanentSubjects || '').split(',').filter(Boolean));
            const candidates = Array.from(subjectSelect.options)
                .filter((option) => option.value && subjects.has(option.value))
                .map((option, order) => ({
                    id: option.value,
                    order,
                    count: counts.get(option.value) || 0,
                    permanent: permanentSubjects.has(option.value),
                }));
            if (!candidates.length) {
                subjectSelect.value = '';
                status.textContent = 'El profesor seleccionado no tiene materias asignadas en su horario. Elija otro profesor.';
                return;
            }
            candidates.sort((first, second) =>
                second.count - first.count
                || Number(second.permanent) - Number(first.permanent)
                || first.order - second.order
            );
            subjectSelect.value = candidates[0].id;
            subjectSelect.dispatchEvent(new Event('change', {bubbles: true}));
        };

        const loadAvailableTeachers = async () => {
            const gradeOption = gradeSelect.selectedOptions[0];
            blockInput.value = gradeOption?.dataset.blockId || '';
            teacherSelect.value = '';
            subjectSelect.value = '';
            teacherOptions.forEach((option) => {
                option.hidden = true;
                option.disabled = true;
            });
            if (!gradeSelect.value || !blockInput.value) {
                status.textContent = 'Seleccione grado para cargar profesores disponibles en este horario.';
                return;
            }

            const version = ++requestVersion;
            status.textContent = 'Consultando profesores disponibles para esta hora...';
            const params = new URLSearchParams({
                fecha: form.dataset.availabilityDate || '',
                dia: form.dataset.availabilityDay || '',
                id_bloque: blockInput.value,
            });
            try {
                const response = await fetch(`${form.dataset.availabilityUrl}&${params.toString()}`);
                const data = await response.json();
                if (version !== requestVersion) return;
                if (!response.ok) throw new Error(data.error || 'No se pudo consultar la disponibilidad de profesores.');

                const availableIds = new Set(
                    data.profesores.filter((profesor) => profesor.disponible).map((profesor) => String(profesor.id))
                );
                teacherOptions.forEach((option) => {
                    const available = availableIds.has(option.value);
                    option.hidden = !available;
                    option.disabled = !available;
                });
                const count = availableIds.size;
                status.textContent = count
                    ? `${count} profesor(es) disponible(s) para este grado, día y horario.`
                    : 'No hay profesores disponibles para este horario.';
            } catch (error) {
                if (version !== requestVersion) return;
                status.textContent = error.message || 'No se pudo consultar la disponibilidad de profesores.';
            }
        };

        gradeSelect.addEventListener('change', loadAvailableTeachers);
        teacherSelect.addEventListener('change', selectMostUsedSubject);
    };

    const setupRoomClassEditor = async (form) => {
        const status = form.querySelector('[data-room-availability-status]');
        const teacherSelect = form.querySelector('[name="id_profesor"]');
        const subjectSelect = form.querySelector('[name="id_materia"]');
        const selectMostUsedSubject = () => {
            const teacherOption = teacherSelect?.selectedOptions[0];
            if (!teacherOption?.value || !subjectSelect) return;
            const taughtCounts = new Map(
                (teacherOption.dataset.subjectCounts || '')
                    .split(',')
                    .filter(Boolean)
                    .map((item) => item.split(':'))
                    .map(([id, count]) => [id, Number(count)])
            );
            const taughtSubjects = new Set((teacherOption.dataset.subjects || '').split(',').filter(Boolean));
            const permanentSubjects = new Set((teacherOption.dataset.permanentSubjects || '').split(',').filter(Boolean));
            const candidates = Array.from(subjectSelect.options)
                .filter((option) => option.value && taughtSubjects.has(option.value))
                .map((option, order) => ({
                    id: option.value,
                    order,
                    count: taughtCounts.get(option.value) || 0,
                    permanent: permanentSubjects.has(option.value),
                }));
            if (!candidates.length) return;
            candidates.sort((first, second) =>
                second.count - first.count
                || Number(second.permanent) - Number(first.permanent)
                || first.order - second.order
            );
            subjectSelect.value = candidates[0].id;
            subjectSelect.dispatchEvent(new Event('change', {bubbles: true}));
        };
        teacherSelect?.addEventListener('change', selectMostUsedSubject);
        if (teacherSelect?.value && !subjectSelect?.value) selectMostUsedSubject();
        const params = new URLSearchParams({
            fecha: form.dataset.availabilityDate || '',
            dia: form.dataset.availabilityDay || '',
            hora_inicio: form.dataset.availabilityStart || '',
            hora_fin: form.dataset.availabilityEnd || '',
            id_horario: form.querySelector('[name="id_horario"]')?.value || '',
            ignorar_adicion: form.dataset.availabilityAddition || '',
        });

        try {
            const response = await fetch(`${form.dataset.availabilityUrl}&${params.toString()}`);
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'No se pudo consultar la disponibilidad.');

            const unavailableResources = [];
            [
                ['id_profesor', data.profesores, 'profesor'],
            ].forEach(([fieldName, resources, label]) => {
                const select = form.querySelector(`[name="${fieldName}"]`);
                if (!select) return;
                const availability = new Map(resources.map((resource) => [String(resource.id), resource]));
                const currentValue = select.value;
                Array.from(select.options).forEach((option) => {
                    if (!option.value) return;
                    const resource = availability.get(option.value);
                    const available = resource?.disponible === true;
                    if (fieldName === 'id_profesor') {
                        option.hidden = !available;
                        option.disabled = !available;
                        option.dataset.teacherName ||= option.textContent.trim().replace(/\s+·\s+(Disponible|Ocupado.*|No disponible)$/, '');
                        option.textContent = `${option.dataset.teacherName} · Disponible`;
                    } else {
                        option.hidden = !available;
                        option.disabled = !available;
                    }
                    if (option.value === currentValue && !available) {
                        unavailableResources.push(`${label}: ${resource?.conflicto || 'recurso inactivo o no disponible'}`);
                    }
                });
                if (currentValue && !availability.get(currentValue)?.disponible) {
                    select.value = '';
                }
                select._syncSearchableSelect?.();
            });

            if (status) {
                status.textContent = unavailableResources.length
                    ? `El docente asignado no está disponible (${unavailableResources.join('; ')}). Elija uno de los ${data.profesores.filter((item) => item.disponible).length} docentes disponibles que aparecen en la lista.`
                    : `Se muestran ${data.profesores.filter((item) => item.disponible).length} docentes disponibles para este horario.`;
            }
        } catch (error) {
            if (status) status.textContent = error.message || 'No se pudo consultar la disponibilidad.';
        }
    };

    const setupClassEditor = (form) => {
        const dateInput = form.querySelector('[name="fecha"]');
        const blockSelect = form.querySelector('[name="id_bloque"]');
        const subjectSelect = form.querySelector('[name="id_materia"]');
        const teacherSelect = form.querySelector('[name="id_profesor"]');
        const roomSelect = form.querySelector('[name="id_salon"]');
        const stateSelect = form.querySelector('[name="estado"]');
        const status = form.querySelector('[data-class-resource-status]');
        const observation = form.querySelector('[name="observacion"]');
        const autoObservations = form.querySelector('[name="auto_observaciones"]');
        const restoreOriginal = form.querySelector('[name="restaurar_original"]');
        const scheduleOccupancies = JSON.parse(blockSelect.dataset.occupancies || '{}');
        const splitObservation = (value) => {
            const automaticLines = [];
            const manualLines = [];
            for (const line of String(value || '').split(/\r?\n/)) {
                const trimmed = line.trim();
                if (/^(Cambio puntual:|Profesor asignado:|Materia:|Salón asignado:|El resto de la jornada continúa con el horario habitual\.|Clase cancelada desde el horario semanal\.)/i.test(trimmed)) {
                    automaticLines.push(trimmed);
                } else if (trimmed) {
                    manualLines.push(trimmed);
                }
            }
            return {automaticText: automaticLines.join('\n'), manualText: manualLines.join('\n')};
        };
        const replaceAutomaticObservation = (automaticText) => {
            const {manualText} = splitObservation(observation.value);
            observation.value = [automaticText, manualText].filter(Boolean).join('\n');
        };
        const removeAutomaticObservation = () => {
            observation.value = splitObservation(observation.value).manualText;
        };
        const initial = {
            teacher: String(teacherSelect.value || ''),
            subject: String(subjectSelect.value || ''),
            room: String(roomSelect.value || ''),
            teacherName: form.dataset.originalTeacher || '',
            subjectName: form.dataset.originalSubject || '',
            roomName: form.dataset.originalRoom || '',
        };
        const syncResourceState = () => {
            const isFree = stateSelect.value === 'DESCANSO';
            subjectSelect.required = !isFree;
            const prompts = new Map([
                [subjectSelect, 'Elige una materia'],
                [teacherSelect, 'Elige un profesor'],
                [roomSelect, 'Elige un salón'],
            ]);
            [subjectSelect, teacherSelect, roomSelect].forEach((select) => {
                const field = select.closest('[data-class-resource]');
                const searchable = field?.querySelector('.select-search');
                const searchInput = searchable?.querySelector('.select-search-input');
                const noApplicable = field?.querySelector('[data-not-applicable]');
                const placeholder = Array.from(select.options).find((option) => option.value === '');
                const prompt = isFree ? 'No aplica' : prompts.get(select);
                if (placeholder) placeholder.textContent = prompt;
                select.disabled = isFree;
                if (searchable) searchable.hidden = isFree;
                if (searchInput) searchInput.placeholder = prompt;
                if (noApplicable) noApplicable.hidden = !isFree;
                select._syncSearchableSelect?.();
            });
        };
        const subjectOptions = Array.from(subjectSelect.options).filter((option) => option.value).map((option) => option.cloneNode(true));
        const teacherOptions = Array.from(teacherSelect.options).filter((option) => option.value).map((option) => option.cloneNode(true));
        const roomOptions = Array.from(roomSelect.options).filter((option) => option.value).map((option) => option.cloneNode(true));
        const updateBlockOptions = () => {
            const day = weekdayFromDate(dateInput.value);
            const scheduleId = form.querySelector('[name="id_horario"]')?.value || '';
            const occupiedIntervals = (scheduleOccupancies[day] || []).filter((occupancy) => String(occupancy.id) !== scheduleId);
            const toMinutes = (time) => {
                const [hours, minutes] = time.split(':').map(Number);
                return hours * 60 + minutes;
            };
            for (const option of blockSelect.options) {
                if (!option.value) continue;
                const start = toMinutes(option.dataset.start);
                const end = toMinutes(option.dataset.end);
                const available = !day || !occupiedIntervals.some((occupancy) => (
                    start < toMinutes(occupancy.end) && toMinutes(occupancy.start) < end
                ));
                option.hidden = !available;
                option.disabled = !available;
            }
            if (blockSelect.selectedOptions[0]?.disabled) {
                blockSelect.value = '';
            }
        };
        let availableTeachers = new Set(teacherOptions.map((option) => option.value));
        let availableRooms = new Set();
        let availabilityLoaded = false;
        let requestVersion = 0;

        const renderGroupedOptions = (select, options, roomId, roomName, relatedId, relatedName, roomLabel, relatedLabel, relationKey) => {
            const selectedValue = select.value;
            const placeholder = Array.from(select.options).find((option) => !option.value)?.cloneNode(true);
            const groups = [[], [], []];
            for (const option of options) {
                if (select === teacherSelect && !availableTeachers.has(option.value)) {
                    if (option.value === selectedValue) {
                        const unavailableOption = option.cloneNode(true);
                        const teacherName = unavailableOption.dataset.teacherName || unavailableOption.textContent.trim();
                        unavailableOption.textContent = `${teacherName} · No disponible (se conserva)`;
                        unavailableOption.disabled = true;
                        groups[2].push(unavailableOption);
                    }
                    continue;
                }
                const hasRoom = roomId && (option.dataset.rooms || '').split(',').includes(roomId);
                const hasRelation = relatedId && (option.dataset[relationKey] || '').split(',').includes(relatedId);
                if (hasRoom) groups[0].push(option.cloneNode(true));
                if (hasRelation) groups[1].push(option.cloneNode(true));
                if (relatedId ? !hasRelation : roomId ? !hasRoom : true) {
                    groups[2].push(option.cloneNode(true));
                }
            }

            select.replaceChildren();
            if (placeholder) select.append(placeholder);
            const labels = [roomId ? `${roomLabel} ${roomName}` : '', relatedId ? `${relatedLabel} ${relatedName}` : '', 'Otros'];
            groups.forEach((optionsInGroup, index) => {
                if (!optionsInGroup.length) return;
                const group = document.createElement('optgroup');
                group.label = labels[index];
                optionsInGroup.forEach((option) => group.append(option));
                select.append(group);
            });
            if (Array.from(select.options).some((option) => option.value === selectedValue)) {
                select.value = selectedValue;
            }
        };

        const renderRoomGroups = () => {
            const selectedValue = roomSelect.value;
            const placeholder = Array.from(roomSelect.options).find((option) => !option.value)?.cloneNode(true);
            const teacherId = teacherSelect.value;
            const teacherName = teacherSelect.selectedOptions[0]?.textContent || '';
            const subjectId = subjectSelect.value;
            const subjectName = subjectSelect.selectedOptions[0]?.textContent || '';
            const teacherRooms = new Set((teacherSelect.selectedOptions[0]?.dataset.rooms || '').split(',').filter(Boolean));
            const subjectRooms = new Set((subjectSelect.selectedOptions[0]?.dataset.rooms || '').split(',').filter(Boolean));
            const groups = [[], [], []];

            for (const option of roomOptions) {
                const optionClone = option.cloneNode(true);
                const roomValue = optionClone.value;
                const inTeacher = teacherId && teacherRooms.has(roomValue);
                const inSubject = subjectId && subjectRooms.has(roomValue);
                if (inTeacher) groups[0].push(optionClone.cloneNode(true));
                if (inSubject) groups[1].push(optionClone.cloneNode(true));
                if (subjectId ? !inSubject : teacherId ? !inTeacher : true) {
                    groups[2].push(optionClone);
                }
            }

            roomSelect.replaceChildren();
            if (placeholder) roomSelect.append(placeholder);

            const labels = [
                teacherId ? `Salones de ${teacherName}` : '',
                subjectId ? `Salones de ${subjectName}` : '',
                'Otros',
            ];

            groups.forEach((optionsInGroup, index) => {
                if (!optionsInGroup.length) return;
                const group = document.createElement('optgroup');
                group.label = labels[index];
                optionsInGroup.forEach((option) => group.append(option));
                roomSelect.append(group);
            });

            if (Array.from(roomSelect.options).some((option) => option.value === selectedValue)) {
                roomSelect.value = selectedValue;
            }

            for (const option of roomSelect.options) {
                if (!option.value) continue;
                const available = availableRooms.size === 0 || availableRooms.has(option.value);
                option.hidden = !available;
                option.disabled = !available;
            }
        };

        const applyUniqueAssignments = () => {
            const roomId = roomSelect.value;
            const subjectId = subjectSelect.value;
            const teacherId = teacherSelect.value;

            if (!teacherSelect._searchClearing && subjectId && !teacherId) {
                const permanentTeachers = teacherOptions.filter((option) =>
                    availableTeachers.has(option.value)
                    && (option.dataset.permanentSubjects || '').split(',').includes(subjectId)
                );
                if (permanentTeachers.length === 1) {
                    teacherSelect.value = permanentTeachers[0].value;
                }
            }

            if (!teacherSelect._searchClearing && roomId && !teacherSelect.value) {
                const permanentRoomTeachers = teacherOptions.filter((option) =>
                    availableTeachers.has(option.value)
                    && (option.dataset.permanentRooms || '').split(',').includes(roomId)
                );
                if (permanentRoomTeachers.length === 1) {
                    teacherSelect.value = permanentRoomTeachers[0].value;
                }
            }

            if (!teacherSelect._searchClearing && roomId && subjectId && !teacherSelect.value) {
                const matchingTeachers = teacherOptions.filter((option) =>
                    availableTeachers.has(option.value)
                    && (option.dataset.rooms || '').split(',').includes(roomId)
                    && (option.dataset.subjects || '').split(',').includes(subjectId)
                );
                if (matchingTeachers.length === 1) {
                    teacherSelect.value = matchingTeachers[0].value;
                }
            }

            if (!teacherSelect._searchClearing && roomId && !teacherSelect.value && !subjectId) {
                const matchingTeachers = teacherOptions.filter((option) => {
                    const teachesSubject = !subjectId || (option.dataset.subjects || '').split(',').includes(subjectId);
                    return availableTeachers.has(option.value)
                        && (option.dataset.rooms || '').split(',').includes(roomId)
                        && teachesSubject;
                });
                if (matchingTeachers.length === 1) {
                    teacherSelect.value = matchingTeachers[0].value;
                }
            }

            if (!subjectSelect._searchClearing && roomId && teacherSelect.value && !subjectId) {
                const matchingSubjects = subjectOptions.filter((option) =>
                    (option.dataset.teachers || '').split(',').includes(teacherId)
                    && (option.dataset.rooms || '').split(',').includes(roomId)
                );
                if (matchingSubjects.length === 1) {
                    subjectSelect.value = matchingSubjects[0].value;
                }
            }
        };

        const renderRelatedOptions = () => {
            applyUniqueAssignments();
            const roomId = roomSelect.value;
            const roomName = roomSelect.selectedOptions[0]?.dataset.roomName
                || roomSelect.selectedOptions[0]?.textContent.replace(/ · (Libre|Ocupado)$/, '') || '';
            const subjectId = subjectSelect.value;
            const subjectName = subjectSelect.selectedOptions[0]?.textContent || '';
            const teacherId = teacherSelect.value;
            const teacherName = teacherSelect.selectedOptions[0]?.textContent || '';

            renderGroupedOptions(subjectSelect, subjectOptions, roomId, roomName, teacherId, teacherName, 'Materias de', 'Materias de', 'teachers');
            renderGroupedOptions(teacherSelect, teacherOptions, roomId, roomName, subjectId, subjectName, 'Profesores de', 'Profesores de', 'subjects');
            renderRoomGroups();
        };

        const selectAvailableRoom = () => {
            if (!roomSelect.value || !availableRooms.has(roomSelect.value)) {
                roomSelect.value = Array.from(roomSelect.options).find((option) => option.value && availableRooms.has(option.value))?.value || '';
            }
        };

        const updateChangedStyles = () => {
            teacherSelect.classList.remove('changed-field');
            subjectSelect.classList.remove('changed-subject-field');
            roomSelect.classList.remove('changed-field');
            teacherSelect.classList.toggle('changed-field', Boolean(teacherSelect.value && teacherSelect.value !== initial.teacher));
            subjectSelect.classList.toggle('changed-subject-field', Boolean(subjectSelect.value && subjectSelect.value !== initial.subject));
            roomSelect.classList.toggle('changed-field', Boolean(roomSelect.value && roomSelect.value !== initial.room));
        };

        const selectMostUsedSubjectForTeacher = () => {
            const teacherOption = teacherSelect.selectedOptions[0];
            const teacherId = teacherOption?.value;
            if (!teacherId) return;
            const assignedSubjects = new Set((teacherOption.dataset.subjects || '').split(',').filter(Boolean));
            const matchingSubjects = subjectOptions
                .filter((option) => assignedSubjects.has(option.value)
                    || (option.dataset.teachers || '').split(',').includes(teacherId))
                .map((option) => {
                    const counts = (option.dataset.teacherCounts || '').split(',');
                    const match = counts
                        .map((entry) => entry.split(':'))
                        .find(([id]) => id === teacherId);
                    return {option, count: Number(match?.[1] || 0)};
                })
                .sort((first, second) => second.count - first.count);
            const nextSubject = matchingSubjects[0]?.option.value;
            if (nextSubject && subjectSelect.value !== nextSubject) {
                subjectSelect.value = nextSubject;
                subjectSelect.dispatchEvent(new Event('change', {bubbles: true}));
            }
        };

        const selectRoomForTeacher = () => {
            const teacherOption = teacherSelect.selectedOptions[0];
            const subjectOption = subjectSelect.selectedOptions[0];
            if (!teacherOption) return;

            const teacherRooms = new Set((teacherOption.dataset.rooms || '').split(',').filter(Boolean));
            const subjectRooms = new Set((subjectOption?.dataset.rooms || '').split(',').filter(Boolean));
            const teacherRoomCounts = new Map((teacherOption.dataset.roomCounts || '').split(',').filter(Boolean).map((entry) => {
                const [roomId, total] = entry.split(':');
                return [String(roomId), Number(total || 0)];
            }));
            const compatibleRooms = Array.from(roomSelect.options)
                .filter((option) => option.value)
                .filter((option) => {
                    const roomId = String(option.value);
                    const teacherAllows = teacherRooms.has(roomId);
                    const subjectAllows = !subjectOption || !subjectOption.value || subjectRooms.has(roomId);
                    const availabilityAllows = !availabilityLoaded || availableRooms.has(roomId);
                    return teacherAllows && subjectAllows && availabilityAllows;
                })
                .sort((first, second) => {
                    const roomA = String(first.value);
                    const roomB = String(second.value);
                    const score = (roomId) => {
                        const count = teacherRoomCounts.get(String(roomId)) || 0;
                        return count * 100 + (teacherRooms.has(roomId) ? 10 : 0) + (subjectRooms.has(roomId) ? 5 : 0);
                    };
                    return score(roomB) - score(roomA);
                });

            const chosenRoom = compatibleRooms[0]?.value || '';
            if (!chosenRoom) {
                const fallbackRoom = Array.from(roomSelect.options).find((option) => option.value && teacherRooms.has(String(option.value)))?.value || '';
                if (fallbackRoom) roomSelect.value = fallbackRoom;
                return;
            }

            if (roomSelect.value !== chosenRoom) {
                roomSelect.value = chosenRoom;
                roomSelect.dispatchEvent(new Event('change', {bubbles: true}));
            }
        };

        const updateObservation = () => {
            if ((autoObservations && !autoObservations.checked)
                || form.querySelector('[name="action"]')?.value !== 'update') return;
            if (stateSelect.value === 'CANCELADA') {
                replaceAutomaticObservation('Clase cancelada desde el horario semanal.');
                return;
            }
            const changes = [];
            if (teacherSelect.value !== initial.teacher) {
                changes.push(`Profesor asignado: ${teacherSelect.selectedOptions[0]?.textContent || 'Sin profesor'}.`);
            }
            if (subjectSelect.value !== initial.subject) {
                changes.push(`Materia: ${subjectSelect.selectedOptions[0]?.textContent || 'Sin materia'}.`);
            }
            if (roomSelect.value !== initial.room) {
                changes.push(`Salón asignado: ${roomSelect.selectedOptions[0]?.dataset.roomName || roomSelect.selectedOptions[0]?.textContent || 'Sin salón'}.`);
            }
            if (!changes.length) {
                replaceAutomaticObservation('');
                return;
            }
            const dateParts = dateInput.value.split('-').map(Number);
            const dayName = dateInput.value
                ? new Date(Date.UTC(dateParts[0], dateParts[1] - 1, dateParts[2])).toLocaleDateString('es-CO', {weekday: 'long', timeZone: 'UTC'})
                : 'día seleccionado';
            const blockOption = blockSelect.selectedOptions[0];
            const blockNumber = blockOption?.dataset.number || 'seleccionado';
            const start = blockOption?.dataset.start || '';
            const end = blockOption?.dataset.end || '';
            const timeRange = start && end ? ` (${start}-${end})` : '';
            replaceAutomaticObservation(`Cambio puntual: ${dayName}, bloque ${blockNumber}${timeRange}.\n${changes.join('\n')}\nEl resto de la jornada continúa con el horario habitual.`);
        };
        form._updateClassObservation = updateObservation;
        form._removeAutomaticObservation = removeAutomaticObservation;

        const loadAvailability = async () => {
            const day = weekdayFromDate(dateInput.value);
            const block = blockSelect.value;
            if (!day || !block) {
                status.textContent = 'Seleccione fecha y bloque para filtrar recursos.';
                return;
            }

            const version = ++requestVersion;
            status.textContent = 'Actualizando opciones disponibles...';
            const params = new URLSearchParams({
                dia: day,
                id_bloque: block,
                fecha: dateInput.value,
                id_horario: form.querySelector('[name="id_horario"]')?.value || '',
            });
            try {
                const response = await fetch(`${form.dataset.availabilityUrl}&${params.toString()}`);
                const data = await response.json();
                if (version !== requestVersion) return;
                if (!response.ok) throw new Error(data.error || 'No se pudo consultar la disponibilidad.');

                availableTeachers = new Set(data.profesores.filter((teacher) => teacher.disponible).map((teacher) => String(teacher.id)));
                availableRooms = new Set(data.salones.filter((room) => room.disponible).map((room) => String(room.id)));
                availabilityLoaded = true;

                for (const option of roomSelect.options) {
                    if (!option.value) continue;
                    const available = availableRooms.has(option.value);
                    const roomName = option.dataset.roomName || option.textContent.replace(/ · (Libre|Ocupado)$/, '');
                    option.hidden = !available;
                    option.disabled = !available;
                    option.dataset.roomName = roomName;
                    option.textContent = roomName;
                }
                if (teacherSelect.value) selectRoomForTeacher();
                renderRelatedOptions();
                updateChangedStyles();
                updateObservation();
                status.textContent = `${availableTeachers.size} profesores y ${availableRooms.size} salones disponibles para este horario y fecha.`;
            } catch (error) {
                if (version !== requestVersion) return;
                status.textContent = error.message;
            }
        };

        subjectSelect.addEventListener('change', () => {
            selectAvailableRoom();
            renderRelatedOptions();
            updateChangedStyles();
            updateObservation();
        });
        teacherSelect.addEventListener('change', () => {
            selectMostUsedSubjectForTeacher();
            selectAvailableRoom();
            selectRoomForTeacher();
            renderRelatedOptions();
            updateChangedStyles();
            updateObservation();
        });
        roomSelect.addEventListener('change', () => {
            renderRelatedOptions();
            updateChangedStyles();
            updateObservation();
        });
        stateSelect.addEventListener('change', () => {
            const resolution = form.querySelector('[name="resolucion_cancelacion"]');
            if (resolution && !['PROGRAMADA', 'DESCANSO'].includes(stateSelect.value)) {
                resolution.value = '';
            }
            syncResourceState();
            updateObservation();
        });
        form.querySelectorAll('[data-cancel-resolution-choice]').forEach((button) => {
            button.addEventListener('click', () => {
                const resolution = form.querySelector('[name="resolucion_cancelacion"]');
                const panel = form.querySelector('[data-cancel-resolution-panel]');
                if (!resolution || !panel) return;
                resolution.value = button.dataset.cancelResolutionChoice;
                stateSelect.value = resolution.value === 'descanso' ? 'DESCANSO' : 'PROGRAMADA';
                stateSelect.dispatchEvent(new Event('change', {bubbles: true}));
                if (resolution.value === 'profesor') {
                    teacherSelect.value = '';
                    teacherSelect.dispatchEvent(new Event('change', {bubbles: true}));
                    teacherSelect.focus();
                }
                panel.hidden = true;
            });
        });
        syncResourceState();
        window.setTimeout(syncResourceState, 0);
        updateBlockOptions();
        dateInput.addEventListener('change', () => {
            updateBlockOptions();
            loadAvailability();
        });
        blockSelect.addEventListener('change', loadAvailability);
        [dateInput, blockSelect, subjectSelect, teacherSelect, roomSelect].forEach((field) => {
            field.addEventListener('change', () => { restoreOriginal.value = '0'; });
        });
        observation.addEventListener('input', () => { restoreOriginal.value = '0'; });
        form.addEventListener('reset', () => {
            window.setTimeout(() => {
                restoreOriginal.value = '1';
                teacherSelect.classList.remove('changed-field');
                subjectSelect.classList.remove('changed-subject-field');
                roomSelect.classList.remove('changed-field');
                observation.dataset.userEdited = 'false';
                updateChangedStyles();
                syncResourceState();
                status.textContent = 'Valores originales restaurados. La disponibilidad se validará al guardar.';
            }, 0);
        });
        form.addEventListener('submit', async (event) => {
            if (stateSelect.value === 'DESCANSO' || event.submitter?.name === 'operation' || !teacherSelect.value || !subjectSelect.value) return;
            const teacherIds = (subjectSelect.selectedOptions[0]?.dataset.teachers || '').split(',').filter(Boolean);
            if (teacherIds.includes(teacherSelect.value)) return;

            event.preventDefault();
            const matchingSubjects = subjectOptions.filter((option) =>
                (option.dataset.teachers || '').split(',').includes(teacherSelect.value)
            );
            const matchingSubjectsInRoom = matchingSubjects.filter((option) =>
                roomSelect.value && (option.dataset.rooms || '').split(',').includes(roomSelect.value)
            );
            let candidates = matchingSubjectsInRoom.length ? matchingSubjectsInRoom : matchingSubjects;

            if (candidates.length > 1) {
                const counts = candidates.map((option) => {
                    const entry = (option.dataset.teacherCounts || '').split(',').find((item) => item.startsWith(`${teacherSelect.value}:`));
                    return {option, total: Number(entry?.split(':')[1] || 0)};
                });
                const mostFrequent = Math.max(...counts.map((item) => item.total));
                const topCandidates = counts.filter((item) => item.total === mostFrequent && mostFrequent > 0);
                candidates = topCandidates.length === 1 ? [topCandidates[0].option] : [];
            }

            if (candidates.length !== 1) {
                const message = candidates.length
                    ? 'Este profesor tiene varias materias posibles. Selecciona manualmente la materia correcta antes de guardar.'
                    : 'Este profesor no tiene una materia compatible registrada. Selecciona una materia que sí imparta antes de guardar.';
                status.textContent = message;
                status.dataset.state = 'unavailable';
                window.alert(message);
                return;
            }

            const previousSubject = subjectSelect.value;
            const previousSubjectName = subjectSelect.selectedOptions[0]?.textContent || 'sin materia';
            const nextSubject = candidates[0];
            subjectSelect.value = nextSubject.value;
            renderRelatedOptions();
            updateObservation();
            const teacherName = teacherSelect.selectedOptions[0]?.textContent || 'el profesor elegido';
            const message = `La materia cambiará de ${previousSubjectName} a ${nextSubject.textContent.trim()} para coincidir con ${teacherName}. ¿Deseas guardar ambos cambios?`;
            if (await showConfirmDialog('Confirmar cambio de materia', message)) {
                form.requestSubmit(event.submitter || undefined);
                return;
            }

            subjectSelect.value = previousSubject;
            renderRelatedOptions();
            updateObservation();
        });
        observation.addEventListener('input', () => { observation.dataset.userEdited = 'true'; });
        renderRelatedOptions();
        loadAvailability();
    };

    const setupResourceFinder = (form) => {
        const finder = form.querySelector('[data-resource-finder]');
        if (!finder) return;

        const scheduleSelect = form.querySelector('[data-schedule-context]');
        const gradeSelect = finder.querySelector('[data-resource-grade]');
        const dateInput = finder.querySelector('[data-resource-date]');
        const dayLabel = finder.querySelector('[data-resource-day]');
        const blockSelect = finder.querySelector('[data-resource-block]');
        const resourceType = finder.querySelector('[data-resource-type]');
        const resultsSelect = finder.querySelector('[data-resource-results]');
        const status = finder.querySelector('[data-resource-status]');
        const searchButton = finder.querySelector('[data-find-resource]');
        const noveltyDate = form.querySelector('[name="fecha"]');

        const updateDayLabel = () => {
            if (!dateInput.value || !dayLabel) return;
            const [year, month, day] = dateInput.value.split('-').map(Number);
            const weekday = new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('es-CO', {weekday: 'long', timeZone: 'UTC'});
            dayLabel.textContent = weekday.charAt(0).toUpperCase() + weekday.slice(1);
        };

        const filterBlocks = () => {
            const jornada = gradeSelect.selectedOptions[0]?.dataset.jornada || '';
            for (const option of blockSelect.options) {
                if (!option.value) continue;
                option.hidden = !jornada || option.dataset.jornada !== jornada;
            }
            if (blockSelect.selectedOptions[0]?.hidden) blockSelect.value = '';
        };

        const syncFromSchedule = () => {
            const selectedSchedule = scheduleSelect?.selectedOptions[0];
            if (!selectedSchedule?.value) return;
            gradeSelect.value = selectedSchedule.dataset.grade || '';
            filterBlocks();
            blockSelect.value = selectedSchedule.dataset.block || '';
            if (noveltyDate?.value) dateInput.value = noveltyDate.value;
            if (resourceType && form.querySelector('[name="tipo_novedad"]')?.value === 'CAMBIO_SALON') resourceType.value = 'salon';
            if (resourceType && form.querySelector('[name="tipo_novedad"]')?.value === 'CAMBIO_PROFESOR') resourceType.value = 'profesor';
            updateDayLabel();
        };

        const searchResources = async () => {
            if (!gradeSelect.value || !dateInput.value || !blockSelect.value) {
                status.textContent = 'Selecciona grado, fecha y hora para buscar.';
                return;
            }
            const params = new URLSearchParams({
                grado: gradeSelect.value,
                fecha: dateInput.value,
                id_bloque: blockSelect.value,
                recurso: resourceType.value,
            });
            const scheduleId = scheduleSelect?.value || form.querySelector('[name="id_horario"]')?.value;
            if (scheduleId) params.set('id_horario', scheduleId);
            status.textContent = 'Buscando disponibilidad...';
            searchButton.disabled = true;
            try {
                const response = await fetch(`${finder.dataset.availabilityUrl}&${params.toString()}`);
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'No se pudo consultar la disponibilidad.');
                resultsSelect.replaceChildren(new Option('Selecciona un recurso disponible', ''));
                for (const resource of data.disponibles) {
                    resultsSelect.add(new Option(resource.nombre, resource.id));
                }
                resultsSelect.disabled = data.disponibles.length === 0;
                status.textContent = data.disponibles.length
                    ? `${data.diaName || ''} ${data.hora_inicio}-${data.hora_fin}: ${data.disponibles.length} ${resourceType.value === 'profesor' ? 'profesores' : 'salones'} disponibles. ${data.disponibles.map((item) => item.nombre).join(', ')}`
                    : `No hay ${resourceType.value === 'profesor' ? 'profesores' : 'salones'} libres para ese horario.`;
                if (data.ocupados.length) {
                    status.textContent += ` Ocupados: ${data.ocupados.map((item) => `${item.nombre} (${item.detalle})`).join('; ')}.`;
                }
            } catch (error) {
                status.textContent = error.message;
                resultsSelect.replaceChildren(new Option('No se encontraron recursos', ''));
                resultsSelect.disabled = true;
            } finally {
                searchButton.disabled = false;
            }
        };

        gradeSelect.addEventListener('change', filterBlocks);
        dateInput.addEventListener('change', () => {
            updateDayLabel();
            if (noveltyDate) {
                noveltyDate.value = dateInput.value;
                updateRoomAvailability(form);
            }
        });
        noveltyDate?.addEventListener('change', () => {
            dateInput.value = noveltyDate.value;
            updateDayLabel();
        });
        scheduleSelect?.addEventListener('change', syncFromSchedule);
        form.querySelector('[name="tipo_novedad"]')?.addEventListener('change', () => {
            if (resourceType) resourceType.value = form.querySelector('[name="tipo_novedad"]').value === 'CAMBIO_SALON' ? 'salon' : 'profesor';
            updateRoomAvailability(form);
        });
        searchButton.addEventListener('click', searchResources);
        resultsSelect.addEventListener('change', () => {
            const selected = resultsSelect.selectedOptions[0];
            if (!selected?.value) return;
            if (resourceType.value === 'profesor') {
                form.querySelector('[name="nuevo_profesor"]').value = selected.value;
                form.querySelector('[name="nuevo_salon"]').value = '';
                form.querySelector('[name="tipo_novedad"]').value = 'CAMBIO_PROFESOR';
            } else {
                form.querySelector('[name="nuevo_profesor"]').value = '';
                form.querySelector('[name="nuevo_salon"]').value = selected.value;
                form.querySelector('[name="tipo_novedad"]').value = 'CAMBIO_SALON';
                updateRoomAvailability(form);
            }
            status.textContent = `${selected.textContent} seleccionado para la novedad.`;
        });
        filterBlocks();
        if (scheduleSelect?.value) syncFromSchedule();
        if (dateInput.value) updateDayLabel();
    };

    const setupNoveltyForm = (form) => {
        const dateInput = form.querySelector('[data-application-date]');
        const calendarSelect = form.querySelector('[data-calendar-select]');
        const startInput = form.querySelector('[data-search-start]');
        const endInput = form.querySelector('[data-search-end]');
        const gradeSelect = form.querySelector('[data-filter-grade]');
        const teacherFilter = form.querySelector('[data-filter-teacher]');
        const subjectFilter = form.querySelector('[data-filter-subject]');
        const roomFilter = form.querySelector('[data-filter-room]');
        const status = form.querySelector('[data-schedule-search-status]');
        const details = form.querySelector('[data-schedule-details]');
        const selectedScheduleInput = form.querySelector('[data-selected-schedule]');
        const teacherSelect = form.querySelector('[data-change-teacher]');
        const subjectSelect = form.querySelector('[data-change-subject]');
        const roomSelect = form.querySelector('[data-change-room]');
        const observation = form.querySelector('[data-observation]');
        const original = {teacher: '', subject: '', room: ''};
        let currentSchedule = null;
        let searchVersion = 0;
        let initialValues = {
            teacher: form.dataset.initialTeacher || '',
            subject: form.dataset.initialSubject || '',
            room: form.dataset.initialRoom || '',
        };

        const updateCalendarTimes = (preserveSelection = true) => {
            [startInput, endInput].forEach((select) => {
                const selectedTime = preserveSelection ? select.value : '';
                const placeholder = select.options[0];
                placeholder.textContent = calendarSelect.value ? 'Selecciona una hora' : 'Selecciona un calendario primero';
                select.disabled = !calendarSelect.value;
                for (const option of Array.from(select.options).slice(1)) {
                    const visible = Boolean(calendarSelect.value) && option.dataset.calendar === calendarSelect.value;
                    option.hidden = !visible;
                    option.disabled = !visible;
                }
                select.value = Array.from(select.options).some((option) => option.value === selectedTime && !option.disabled)
                    ? selectedTime
                    : '';
            });
        };

        const filterGrades = () => {
            const previousGrade = gradeSelect.value;
            gradeSelect.disabled = !calendarSelect.value;
            for (const option of Array.from(gradeSelect.options).slice(1)) {
                const visible = Boolean(calendarSelect.value) && option.dataset.calendar === calendarSelect.value;
                option.hidden = !visible;
                option.disabled = !visible;
            }
            if (previousGrade && gradeSelect.selectedOptions[0]?.dataset.calendar !== calendarSelect.value) {
                gradeSelect.value = '';
            }
            return Boolean(previousGrade && !gradeSelect.value);
        };

        const weekdayFromDate = () => {
            if (!dateInput.value) return null;
            const [year, month, day] = dateInput.value.split('-').map(Number);
            return new Date(Date.UTC(year, month - 1, day)).getUTCDay() || 7;
        };

        const updateDateValidity = () => {
            if (!dateInput.value || !currentSchedule) {
                dateInput.setCustomValidity('');
                form.querySelector('[data-date-feedback]').textContent = '';
                return true;
            }
            const weekday = weekdayFromDate();
            const valid = weekday === Number(currentSchedule.id_dia);
            dateInput.setCustomValidity(valid ? '' : 'La fecha debe coincidir con el día de la clase seleccionada.');
            form.querySelector('[data-date-feedback]').textContent = valid
                ? 'La fecha coincide con el día de la clase.'
                : 'La fecha no coincide con el día de la clase.';
            form.querySelector('[data-date-feedback]').dataset.state = valid ? 'available' : 'unavailable';
            return valid;
        };

        const clearSearchResults = (message = 'Los filtros cambiaron. Busca horarios nuevamente.') => {
            searchVersion += 1;
            selectedScheduleInput.value = '';
            details.hidden = true;
            currentSchedule = null;
            dateInput.setCustomValidity('');
            form.querySelector('[data-date-feedback]').textContent = '';
            status.textContent = message;
        };

        const fillOptions = (select, entries, placeholder, selectedId) => {
            select.replaceChildren(new Option(placeholder, ''));
            entries.forEach((entry) => select.add(new Option(entry.nombre, String(entry.id))));
            if (selectedId && Array.from(select.options).some((option) => option.value === String(selectedId))) {
                select.value = String(selectedId);
            }
        };

        const updateChangedStyles = () => {
            teacherSelect.classList.toggle('changed-field', Boolean(teacherSelect.value && teacherSelect.value !== original.teacher));
            subjectSelect.classList.toggle('changed-subject-field', Boolean(subjectSelect.value && subjectSelect.value !== original.subject));
            roomSelect.classList.toggle('changed-field', Boolean(roomSelect.value && roomSelect.value !== original.room));
        };

        const updateSubjectChoices = (teacherId, preferredSubject = subjectSelect.value, selectMostTaught = false) => {
            const options = Array.from(subjectSelect.options);
            let firstAllowed = '';
            const allowedOptions = [];
            options.forEach((option) => {
                if (!option.value) return;
                const assignedTeachers = (option.dataset.teachers || '').split(',').filter(Boolean);
                const allowed = !teacherId || assignedTeachers.includes(String(teacherId));
                option.hidden = !allowed;
                option.disabled = !allowed;
                if (allowed) {
                    allowedOptions.push(option);
                    if (!firstAllowed) firstAllowed = option.value;
                }
            });
            let selectedOption = allowedOptions.find((option) => option.value === String(preferredSubject));
            if (teacherId && selectMostTaught && allowedOptions.length) {
                const counts = allowedOptions.map((option) => {
                    const prefix = `${teacherId}:`;
                    const count = (option.dataset.teacherCounts || '').split(',').find((item) => item.startsWith(prefix));
                    return {option, count: Number(count?.slice(prefix.length) || 0)};
                });
                const greatestCount = Math.max(...counts.map((item) => item.count));
                const mostTaught = counts.filter((item) => item.count === greatestCount);
                selectedOption = mostTaught.find((item) => item.option.value === String(preferredSubject))?.option
                    || mostTaught[0]?.option;
            }
            const nextValue = selectedOption?.value || firstAllowed;
            if (subjectSelect.value !== nextValue) {
                subjectSelect.value = nextValue;
                subjectSelect.dispatchEvent(new Event('change', {bubbles: true}));
            }
        };

        const assignAvailableRoom = () => {
            const availableRooms = Array.from(roomSelect.options).filter((option) => option.value && option.dataset.available === 'true');
            const alternateRoom = availableRooms.find((option) => option.value !== roomSelect.value);
            if (alternateRoom) {
                roomSelect.value = alternateRoom.value;
            } else if (!availableRooms.some((option) => option.value === roomSelect.value)) {
                roomSelect.value = availableRooms[0]?.value || '';
            }
        };

        const updateObservation = (force = false) => {
            if (!currentSchedule || observation.dataset.userEdited === 'true' || (!force && observation.value.trim())) return;
            const changes = [];
            if (teacherSelect.value !== original.teacher) changes.push(`Profesor: ${currentSchedule.profesor || 'Sin asignar'}, ${teacherSelect.value ? teacherSelect.selectedOptions[0]?.textContent : 'Sin profesor'}`);
            if (subjectSelect.value !== original.subject) changes.push(`Materia: ${currentSchedule.materia}, ${subjectSelect.selectedOptions[0]?.textContent || 'Sin materia'}`);
            if (roomSelect.value !== original.room) changes.push(`Salón: ${currentSchedule.salon || 'Sin salón'}, ${roomSelect.value ? roomSelect.selectedOptions[0]?.textContent : 'Sin salón'}`);
            if (!changes.length) {
                observation.value = '';
                return;
            }
            observation.value = changes.join('\n');
        };

        const loadResources = async (schedule, preserveSelection = false) => {
            if (!dateInput.value || !updateDateValidity()) {
                status.textContent = 'Selecciona una fecha que corresponda al día de clase.';
                return;
            }
            const query = new URLSearchParams({
                grado: schedule.id_grado,
                fecha: dateInput.value,
                id_bloque: schedule.id_bloque,
                id_horario: schedule.id_horario,
                recurso: 'profesor',
            });
            const previous = preserveSelection ? {
                teacher: teacherSelect.value || initialValues.teacher,
                subject: subjectSelect.value || initialValues.subject,
                room: roomSelect.value || initialValues.room,
            } : initialValues;
            form.querySelector('[data-teacher-availability]').textContent = 'Consultando profesores libres...';
            form.querySelector('[data-room-availability]').textContent = 'Consultando salones libres...';
            try {
                const [teacherResponse, roomResponse] = await Promise.all([
                    fetch(`${form.dataset.availabilityUrl}&${query.toString()}`),
                    fetch(`${form.dataset.availabilityUrl}&${new URLSearchParams({...Object.fromEntries(query), recurso: 'salon'}).toString()}`),
                ]);
                const [teacherData, roomData] = await Promise.all([teacherResponse.json(), roomResponse.json()]);
                if (!teacherResponse.ok) throw new Error(teacherData.error || 'No se pudo consultar la disponibilidad.');
                if (!roomResponse.ok) throw new Error(roomData.error || 'No se pudo consultar la disponibilidad.');
                const teachers = teacherData.disponibles.slice();
                const rooms = roomData.disponibles.slice();
                if (schedule.id_profesor && !teachers.some((item) => String(item.id) === String(schedule.id_profesor))) {
                    teachers.unshift({id: schedule.id_profesor, nombre: schedule.profesor || 'Profesor actual'});
                }
                if (schedule.id_salon && !rooms.some((item) => String(item.id) === String(schedule.id_salon))) {
                    rooms.unshift({id: schedule.id_salon, nombre: schedule.salon || 'Salón actual'});
                }
                fillOptions(teacherSelect, teachers, 'Sin cambio de profesor', previous.teacher || schedule.id_profesor);
                fillOptions(roomSelect, rooms, 'Sin cambio de salón', previous.room || schedule.id_salon);
                const availableRoomIds = new Set(roomData.disponibles.map((room) => String(room.id)));
                for (const option of roomSelect.options) {
                    if (option.value) option.dataset.available = String(availableRoomIds.has(option.value));
                }
                original.teacher = String(schedule.id_profesor || '');
                original.subject = String(schedule.id_materia || '');
                original.room = String(schedule.id_salon || '');
                updateSubjectChoices(teacherSelect.value, previous.subject || schedule.id_materia);
                form.querySelector('[data-teacher-availability]').textContent = `${teacherData.disponibles.length} profesores libres. Los ocupados no aparecen.`;
                form.querySelector('[data-room-availability]').textContent = `${roomData.disponibles.length} salones libres. Los ocupados no aparecen.`;
                updateChangedStyles();
                updateObservation();
            } catch (error) {
                form.querySelector('[data-teacher-availability]').textContent = error.message;
                form.querySelector('[data-room-availability]').textContent = error.message;
            }
        };

        const chooseSchedule = async (schedule) => {
            currentSchedule = schedule;
            selectedScheduleInput.value = schedule.id_horario;
            details.hidden = false;
            form.querySelector('[data-summary-grade]').textContent = schedule.id_grado;
            form.querySelector('[data-summary-day]').textContent = schedule.dia;
            form.querySelector('[data-summary-subject]').textContent = schedule.materia;
            form.querySelector('[data-summary-time]').textContent = `${schedule.hora_inicio} - ${schedule.hora_fin}`;
            form.querySelector('[data-summary-teacher]').textContent = schedule.profesor || 'Sin profesor';
            form.querySelector('[data-summary-room]').textContent = schedule.salon || 'Sin salón';
            initialValues = {
                teacher: form.dataset.initialTeacher || String(schedule.id_profesor || ''),
                subject: form.dataset.initialSubject || String(schedule.id_materia || ''),
                room: form.dataset.initialRoom || String(schedule.id_salon || ''),
            };
            await loadResources(schedule, false);
        };

        const searchSchedules = async () => {
            const previousScheduleId = selectedScheduleInput.value;
            clearSearchResults();
            const startOption = startInput.selectedOptions[0];
            const endOption = endInput.selectedOptions[0];
            const startTime = startOption?.dataset.time;
            const endTime = endOption?.dataset.time;
            if (!calendarSelect.value || !dateInput.value || !startOption?.value || !endOption?.value || startOption.value !== endOption.value || !startTime || !endTime || startTime >= endTime || !gradeSelect.value || gradeSelect.selectedOptions[0]?.dataset.calendar !== calendarSelect.value) {
                status.textContent = 'Selecciona calendario, fecha, horas válidas y grado.';
                return;
            }
            const params = new URLSearchParams({dia: String(weekdayFromDate()), hora_inicio: startTime, hora_fin: endTime, grado: gradeSelect.value, id_calendario: calendarSelect.value, id_bloque: startOption.value});
            if (teacherFilter.value) params.set('profesor', teacherFilter.value);
            if (subjectFilter.value) params.set('materia', subjectFilter.value);
            if (roomFilter.value) params.set('salon', roomFilter.value);
            status.textContent = 'Buscando clases...';
            const requestVersion = searchVersion;
            try {
                const response = await fetch(`${form.dataset.searchUrl}&${params.toString()}`);
                const data = await response.json();
                if (requestVersion !== searchVersion) return;
                if (!response.ok) throw new Error(data.error || 'No se pudieron buscar horarios.');
                if (!data.clases.length) {
                    status.textContent = 'No hay una clase que coincida con esos datos.';
                    return;
                }
                const schedule = data.clases.find((item) => String(item.id_horario) === previousScheduleId) || data.clases[0];
                status.textContent = `Horario de ${schedule.id_grado} listo para editar.`;
                await chooseSchedule(schedule);
            } catch (error) {
                if (requestVersion !== searchVersion) return;
                status.textContent = error.message;
            }
        };

        form.querySelector('[data-toggle-filters]').addEventListener('click', (event) => {
            const filters = form.querySelector('[data-optional-filters]');
            filters.hidden = !filters.hidden;
            event.currentTarget.textContent = filters.hidden ? '+ Filtros' : '− Filtros';
        });
        form.querySelector('[data-find-schedules]').addEventListener('click', searchSchedules);
        calendarSelect.addEventListener('change', () => {
            updateCalendarTimes(false);
            const gradeCleared = filterGrades();
            clearSearchResults(gradeCleared
                ? 'El grado no pertenece a este calendario. Selecciona el grado nuevamente.'
                : 'Selecciona un grado y busca horarios nuevamente.');
        });
        startInput.addEventListener('change', () => {
            endInput.value = startInput.value;
            clearSearchResults();
        });
        endInput.addEventListener('change', () => {
            startInput.value = endInput.value;
            clearSearchResults();
        });
        [dateInput, gradeSelect, teacherFilter, subjectFilter, roomFilter].forEach((input) => {
            input.addEventListener('change', () => clearSearchResults());
        });
        teacherSelect.addEventListener('change', () => {
            updateSubjectChoices(teacherSelect.value, subjectSelect.value, true);
            assignAvailableRoom();
            updateChangedStyles();
            updateObservation(true);
        });
        subjectSelect.addEventListener('change', () => { updateChangedStyles(); updateObservation(true); });
        roomSelect.addEventListener('change', () => { updateChangedStyles(); updateObservation(true); });
        observation.addEventListener('input', () => { observation.dataset.userEdited = 'true'; });
        updateCalendarTimes();
        filterGrades();
        const existingScheduleId = selectedScheduleInput.value;
        if (existingScheduleId) searchSchedules();

        form.addEventListener('submit', (event) => {
            if (!selectedScheduleInput.value) {
                event.preventDefault();
                status.textContent = 'Primero busca y selecciona el horario que deseas cambiar.';
                return;
            }
            if (!updateDateValidity()) {
                event.preventDefault();
                dateInput.reportValidity();
                return;
            }
            if (!subjectSelect.value) {
                event.preventDefault();
                subjectSelect.reportValidity();
            }
        });
    };

    document.querySelectorAll('[data-menu-toggle]').forEach((el) => {
        el.addEventListener('click', () => {
            document.getElementById('sidebar')?.classList.toggle('open');
            document.querySelector('.sidebar-backdrop')?.classList.toggle('open');
        });
    });

    const setupCenterInterestBreakOptions = (form) => {
        if (!form || !form.matches('form[data-center-interest-form]')) return;

        const parseMinutes = (value) => {
            if (!value || !/^\d{2}:\d{2}$/.test(value)) return null;
            const [hours, minutes] = value.split(':').map(Number);
            return hours * 60 + minutes;
        };

        const formatMinutes = (minutes) => {
            const hours = Math.floor(minutes / 60) % 24;
            const mins = minutes % 60;
            return String(hours).padStart(2, '0') + ':' + String(mins).padStart(2, '0');
        };

        const refreshBreakOptions = () => {
            const startTime = form.querySelector('[name="evento_inicio"]')?.value ?? '';
            const startMinutes = parseMinutes(startTime);
            const endInput = form.querySelector('[name="evento_fin"]');
            const endDisplay = form.querySelector('[data-event-end-display]');
            const endMinutes = startMinutes === null ? null : startMinutes + 60;
            const endTime = endMinutes !== null && endMinutes < 24 * 60 ? formatMinutes(endMinutes) : '';
            if (endInput) endInput.value = endTime;
            if (endDisplay) endDisplay.value = endTime;
            const validOptions = [];

            if (startMinutes !== null && endMinutes !== null && endMinutes < 24 * 60 && startMinutes < endMinutes) {
                for (let minutes = 0; minutes <= (23 * 60) + 45; minutes += 15) {
                    const breakEnd = minutes + 45;
                    if (minutes < endMinutes || breakEnd > 24 * 60) continue;
                    const overlapsEvent = minutes >= startMinutes && minutes < endMinutes;
                    if (!overlapsEvent) validOptions.push(formatMinutes(minutes));
                }
            }

            const targets = [
                form.querySelector('[name="descanso_compartido"]'),
                form.querySelector('[name="descanso_calendario_1"]'),
                form.querySelector('[name="descanso_calendario_2"]'),
            ];

            targets.forEach((target) => {
                if (!target) return;
                const list = target.list;
                if (!list) return;
                list.innerHTML = validOptions.map((value) => '<option value="' + value + '"></option>').join('');
            });
        };

        const startInput = form.querySelector('[name="evento_inicio"]');
        const endInput = form.querySelector('[name="evento_fin"]');
        startInput?.addEventListener('input', refreshBreakOptions);
        endInput?.addEventListener('input', refreshBreakOptions);
        form.querySelectorAll('[name="modo_descanso"]').forEach((radio) => {
            radio.addEventListener('change', refreshBreakOptions);
        });
        refreshBreakOptions();
    };

    const openModal = (heading, templateId) => {
        const tpl = document.getElementById(templateId);
        if (!tpl || !modal) return;
        title.textContent = heading;
        body.innerHTML = tpl.innerHTML;
        modal.classList.add('open');
        const noveltyForm = body.querySelector('form[data-novelty-form]');
        if (noveltyForm) setupNoveltyForm(noveltyForm);
        const roomCreateEditor = body.querySelector('form[data-room-create-editor]');
        if (roomCreateEditor) setupRoomCreateEditor(roomCreateEditor);
        const roomClassEditor = body.querySelector('form[data-room-class-editor]');
        if (roomClassEditor) setupRoomClassEditor(roomClassEditor);
        const classEditorForm = body.querySelector('form[data-class-editor]');
        if (classEditorForm) setupClassEditor(classEditorForm);
        const gradeForm = body.querySelector('form[data-grade-validation]');
        if (gradeForm) setupGradeDuplicateCheck(gradeForm);
        const centerInterestForm = body.querySelector('form[data-center-interest-form]');
        if (centerInterestForm) setupCenterInterestBreakOptions(centerInterestForm);
        const availabilityForms = body.querySelectorAll('form[data-room-availability]');
        availabilityForms.forEach((availabilityForm) => {
            updateRoomAvailability(availabilityForm);
            setupResourceFinder(availabilityForm);
        });
        body.querySelector('input, select, textarea')?.focus();
    };

    document.querySelectorAll('[data-open-modal]').forEach((btn) => {
        btn.addEventListener('click', () => {
            openModal(btn.dataset.title || 'Formulario', btn.dataset.openModal);
        });
    });

    document.querySelectorAll('[data-professor-day-cancel]').forEach((button) => {
        button.addEventListener('click', async () => {
            const dayName = button.dataset.dayName || 'día seleccionado';
            const confirmed = await showConfirmDialog(
                'Cancelar clases',
                `¿Quieres eliminar estas clases del día ${dayName}?`,
                'Continuar'
            );
            if (!confirmed) return;

            const statusTemplate = document.querySelector('#tpl-profesor-status');
            const statusForm = statusTemplate?.content.querySelector('form[data-professor-status]')?.cloneNode(true);
            if (!statusForm) return;
            statusForm.querySelector('[name="modo_cancelacion"]').value = 'cancel';
            statusForm.dataset.dayName = button.dataset.dayName || '';
            const dayInput = document.createElement('input');
            dayInput.type = 'hidden';
            dayInput.name = 'id_dia';
            dayInput.value = button.dataset.dayId || '';
            statusForm.append(dayInput);
            title.textContent = `Cancelar clases: ${button.dataset.dayName || 'día seleccionado'}`;
            body.replaceChildren(statusForm);
            modal.classList.add('open');
            statusForm.requestSubmit();
        });
    });

    document.querySelectorAll('[data-no-empty-grades]').forEach((button) => {
        button.addEventListener('click', () => {
            showAlertDialog('Sin grados disponibles', 'No hay grados disponibles.');
        });
    });

    document.addEventListener('click', (event) => {
        const editButton = event.target.closest('[data-edit]');
        if (!editButton) return;
        openModal(editButton.dataset.title || 'Editar', editButton.dataset.edit);
    });

    document.addEventListener('click', (event) => {
        const closeTarget = event.target.closest('[data-close-modal]');
        if (closeTarget) {
            modal.classList.remove('open');
        }
        if (event.target.closest('[data-close-modal][aria-label="Cerrar"]')) {
            modal.classList.remove('open');
        }
    });

    document.addEventListener('change', async (event) => {
        const typeSelect = event.target.closest('form[data-calendar-block-form] select[name="tipo"]');
        if (!typeSelect) return;

        const form = typeSelect.closest('form');
        const repartitionInput = form.querySelector('[name="confirmar_reparticion"]');
        if (typeSelect.value !== 'DESCANSO') {
            repartitionInput.value = '0';
            delete form.dataset.partitionConfirmed;
            return;
        }
        if (form.dataset.allowRest !== 'true') {
            typeSelect.value = 'CLASE';
            return;
        }

        const confirmed = await showConfirmDialog(
            'Convertir en descanso',
            '¿Quieres convertir este espacio en descanso?',
            'Convertir'
        );
        if (!confirmed) {
            typeSelect.value = 'CLASE';
            repartitionInput.value = '0';
            return;
        }
        repartitionInput.value = '1';
        form.dataset.partitionConfirmed = 'true';
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form');
        if (!form) return;

        const professorMode = form.matches('form[data-professor-status]')
            ? form.querySelector('[name="modo_cancelacion"]')?.value
            : null;
        if (form.matches('form[data-professor-status]') && ['cancel', 'replace'].includes(professorMode)) {
            event.preventDefault();
            const professorId = form.querySelector('[name="id_profesor"]')?.value;
            const url = new URL(form.dataset.classesUrl || `${window.location.origin}${window.location.pathname}?api=clases_reemplazo_profesor`, window.location.href);
            url.searchParams.set('api', 'clases_reemplazo_profesor');
            url.searchParams.set('id_profesor', professorId || '');
            const dayId = form.querySelector('[name="id_dia"]')?.value || '';
            if (dayId) url.searchParams.set('id_dia', dayId);
            try {
                const response = await fetch(url.toString());
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'No se pudieron consultar las clases del profesor.');
                if (!data.clases || data.clases.length === 0) {
                    if (dayId) {
                        showAlertDialog('Sin clases', `No hay clases programadas para ${form.dataset.dayName || 'este día'}.`);
                        return;
                    }
                    form.submit();
                    return;
                }
                const professorName = form.dataset.professorName || 'este profesor';
                if (professorMode !== 'cancel' && !await showConfirmDialog(
                    'Reemplazar clases',
                    `Se encontraron ${data.clases.length} clase(s) de ${professorName}. Para cada una podrás elegir materia, profesor y salón, o cancelarla.`,
                    'Elegir clases'
                )) return;

                const replacementForm = document.createElement('form');
                replacementForm.method = 'post';
                const csrfField = form.querySelector('input[name="csrf"]');
                if (csrfField) replacementForm.append(csrfField.cloneNode(true));
                const addHidden = (name, value) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = String(value ?? '');
                    replacementForm.append(input);
                };
                addHidden('action', 'toggle');
                addHidden('id_profesor', professorId);
                addHidden('horario_profesor', professorId);
                addHidden('modo_cancelacion', 'replace');
                if (dayId) addHidden('id_dia', dayId);

                const classCategories = [['Clases a reemplazar o cancelar', data.clases]];
                for (const [categoryName, categoryClasses] of classCategories) {
                    const categoryDetails = document.createElement('details');
                    categoryDetails.className = 'replacement-category';
                    categoryDetails.open = true;
                    const categorySummary = document.createElement('summary');
                    categorySummary.textContent = `${categoryName} · ${categoryClasses.length} clase(s)`;
                    categoryDetails.append(categorySummary);

                    if (!categoryClasses.length) {
                        const empty = document.createElement('p');
                        empty.className = 'muted';
                        empty.textContent = 'No hay clases en este grupo.';
                        categoryDetails.append(empty);
                    }

                    const classesByGrade = new Map();
                    categoryClasses.forEach((classItem) => {
                        if (!classesByGrade.has(classItem.id_grado)) classesByGrade.set(classItem.id_grado, []);
                        classesByGrade.get(classItem.id_grado).push(classItem);
                    });
                    for (const [grade, gradeClasses] of classesByGrade) {
                        const gradeGroup = document.createElement('div');
                        gradeGroup.className = 'replacement-grade';
                        const gradeHeading = document.createElement('p');
                        gradeHeading.className = 'replacement-grade-heading';
                        gradeHeading.textContent = `${grade} · ${gradeClasses.length} clase(s)`;
                        gradeGroup.append(gradeHeading);

                        for (const classItem of gradeClasses) {
                            const row = document.createElement('div');
                            row.className = 'replacement-class-row';
                            const detail = document.createElement('p');
                            detail.className = 'replacement-class-detail';
                            detail.textContent = `${classItem.dia} · ${classItem.hora_inicio}-${classItem.hora_fin} · ${classItem.materia} · ${classItem.profesor || professorName} · ${classItem.salon}`;
                            const fields = document.createElement('div');
                            fields.className = 'replacement-class-fields';

                            const actionField = document.createElement('div');
                            actionField.className = 'field';
                            const actionLabel = document.createElement('label');
                            actionLabel.textContent = 'Acción';
                            const actionSelect = document.createElement('select');
                            actionSelect.name = `reemplazos[${classItem.id_horario}][accion]`;
                            actionSelect.dataset.middleClass = classItem.media_jornada ? 'true' : 'false';
                            if (classItem.solo_cancelacion) {
                                actionSelect.append(new Option('Cancelar clase', 'cancelar'));
                            } else {
                                actionSelect.append(new Option('Reemplazar', 'reemplazar'), new Option('Cancelar clase', 'cancelar'));
                            }
                            actionField.append(actionLabel, actionSelect);

                            const subjectField = document.createElement('div');
                            subjectField.className = 'field';
                            const subjectLabel = document.createElement('label');
                            subjectLabel.textContent = 'Materia';
                            const subjectSelect = document.createElement('select');
                            subjectSelect.name = `reemplazos[${classItem.id_horario}][materia]`;
                            subjectSelect.required = true;
                            subjectSelect.append(new Option('Seleccione una materia', ''));
                            const subjectOptions = new Map();
                            classItem.materias_disponibles.forEach((subject) => {
                                const option = new Option(subject.nombre, subject.id_materia);
                                option.dataset.baseLabel = subject.nombre;
                                subjectSelect.add(option);
                                subjectOptions.set(Number(subject.id_materia), {subject, option});
                            });
                            const originalSubject = subjectOptions.get(Number(classItem.id_materia));
                            subjectSelect.value = originalSubject?.option.value || '';
                            subjectField.append(subjectLabel, subjectSelect);

                            const teacherField = document.createElement('div');
                            teacherField.className = 'field';
                            const teacherLabel = document.createElement('label');
                            teacherLabel.textContent = 'Profesor disponible';
                            const teacherSelect = document.createElement('select');
                            teacherSelect.name = `reemplazos[${classItem.id_horario}][profesor]`;
                            teacherSelect.required = true;
                            teacherSelect.append(new Option('Seleccione un profesor', ''));
                            const teacherOptions = new Map();
                            (classItem.profesores_candidatos || []).forEach((teacher) => {
                                const option = new Option(teacher.nombre_completo, teacher.id_profesor);
                                option.dataset.baseLabel = teacher.nombre_completo;
                                teacherSelect.add(option);
                                teacherOptions.set(Number(teacher.id_profesor), {teacher, option});
                            });
                            teacherField.append(teacherLabel, teacherSelect);

                            const roomField = document.createElement('div');
                            roomField.className = 'field';
                            const roomLabel = document.createElement('label');
                            roomLabel.textContent = 'Salón';
                            const roomSelect = document.createElement('select');
                            roomSelect.name = `reemplazos[${classItem.id_horario}][salon]`;
                            roomSelect.dataset.searchableRoom = '1';
                            roomSelect.append(new Option('Sin salón', ''));
                            const roomOptions = new Map();
                            classItem.salones_disponibles.forEach((roomOption) => {
                                const option = new Option(roomOption.nombre, roomOption.id_salon);
                                option.dataset.baseLabel = roomOption.nombre;
                                roomSelect.add(option);
                                roomOptions.set(Number(roomOption.id_salon), {room: roomOption, option});
                            });
                            if (roomOptions.has(Number(classItem.id_salon))) {
                                roomSelect.value = String(classItem.id_salon);
                            }
                            roomField.append(roomLabel, roomSelect);

                            const hasAvailableTeacher = (classItem.profesores_candidatos || []).some((teacher) => !teacher.ocupacion);
                            const subjectsWithTeachers = classItem.materias_disponibles.filter(() => hasAvailableTeacher);
                            const occupancyReason = (occupancy) => {
                                if (!occupancy) return '';
                                const grade = occupancy.id_grado ? `grado ${occupancy.id_grado}` : 'otro grado';
                                const teacher = occupancy.profesor || 'sin profesor';
                                const subject = occupancy.materia || 'sin materia';
                                const room = occupancy.salon || 'sin salón';
                                const time = `${occupancy.hora_inicio || ''}-${occupancy.hora_fin || ''}`;
                                return `Ocupado por ${teacher}, ${subject}, ${grade}, ${room}, ${time}`;
                            };
                            const setOptionState = (option, group, reason = '') => {
                                const baseLabel = option.dataset.baseLabel || option.textContent;
                                option.dataset.searchGroup = group;
                                option.dataset.disabledReason = reason;
                                option.hidden = Boolean(reason);
                                option.textContent = reason ? `${baseLabel} · ${reason}` : baseLabel;
                                option.disabled = Boolean(reason);
                            };
                            const refreshSearchableOptions = (select, groups) => {
                                select.dataset.searchGroups = JSON.stringify(groups);
                                select._syncSearchableSelect?.();
                            };
                            const updateRelatedOptions = () => {
                                const subjectId = Number(subjectSelect.value) || 0;
                                const teacherId = Number(teacherSelect.value) || 0;
                                const roomId = Number(roomSelect.value) || 0;

                                for (const [id, entry] of subjectOptions) {
                                    const teacherMatch = teacherId && entry.subject.profesores.some((teacher) =>
                                        Number(teacher.id_profesor) === teacherId && !teacher.ocupacion
                                    );
                                    const roomMatch = roomId && entry.subject.salones.includes(roomId);
                                    const hasAvailableTeacher = (classItem.profesores_candidatos || []).some((teacher) => !teacher.ocupacion);
                                    const group = teacherMatch && roomMatch ? 'Materia del profesor y salón'
                                        : teacherMatch ? 'Materia del profesor'
                                            : roomMatch ? 'Materia del salón'
                                                : hasAvailableTeacher ? 'Otras materias' : 'Sin profesor disponible';
                                    const reason = hasAvailableTeacher ? '' : 'No hay profesores disponibles a esta hora';
                                    setOptionState(entry.option, group, reason);
                                }
                                if (subjectSelect.selectedOptions[0]?.disabled) subjectSelect.value = '';
                                refreshSearchableOptions(subjectSelect, ['Materia del profesor', 'Materia del salón', 'Materia del profesor y salón', 'Otras materias', 'Sin profesor disponible']);

                                const selectedSubjectId = Number(subjectSelect.value) || 0;
                                const selectedSubject = subjectOptions.get(selectedSubjectId)?.subject;
                                const selectedRoom = roomOptions.get(Number(roomId))?.room;
                                const subjectGroup = selectedSubject
                                    ? `Profesores de ${selectedSubject.nombre}`
                                    : '';
                                const roomGroup = selectedRoom ? `Profesores del salón ${selectedRoom.nombre}` : '';
                                const otherGroup = 'Otros profesores disponibles';
                                const teacherGroups = [roomGroup, subjectGroup, otherGroup].filter(Boolean);
                                for (const [id, entry] of teacherOptions) {
                                    const subjectMatch = selectedSubjectId && entry.teacher.materias.includes(selectedSubjectId);
                                    const roomMatch = roomId && entry.teacher.salones.includes(roomId);
                                    const group = roomMatch ? roomGroup : subjectMatch ? subjectGroup : otherGroup;
                                    const reason = entry.teacher.ocupacion ? occupancyReason(entry.teacher.ocupacion) : '';
                                    setOptionState(entry.option, group, reason);
                                }
                                if (teacherSelect.selectedOptions[0]?.disabled) teacherSelect.value = '';
                                refreshSearchableOptions(teacherSelect, teacherGroups);

                                const selectedTeacherId = Number(teacherSelect.value) || 0;
                                const roomGroups = ['Salón del profesor', 'Salón de materia', 'Salón de profesor y materia', 'Otros salones disponibles', 'Salón ocupado'];
                                for (const [id, entry] of roomOptions) {
                                    const teacherMatch = selectedTeacherId && entry.room.profesores.includes(selectedTeacherId);
                                    const subjectMatch = selectedSubjectId && entry.room.materias.includes(selectedSubjectId);
                                    const group = teacherMatch && subjectMatch ? 'Salón de profesor y materia'
                                        : teacherMatch ? 'Salón del profesor'
                                            : subjectMatch ? 'Salón de materia'
                                                : entry.room.ocupacion ? 'Salón ocupado' : 'Otros salones disponibles';
                                    const reason = occupancyReason(entry.room.ocupacion);
                                    setOptionState(entry.option, group, reason);
                                }
                                if (roomSelect.selectedOptions[0]?.disabled) roomSelect.value = '';
                                refreshSearchableOptions(roomSelect, roomGroups);
                            };
                            actionSelect.value = classItem.solo_cancelacion || professorMode === 'cancel'
                                ? 'cancelar'
                                : (subjectsWithTeachers.length ? 'reemplazar' : 'cancelar');
                            if (!subjectsWithTeachers.length && !classItem.solo_cancelacion) {
                                actionSelect.options[0].disabled = true;
                                subjectSelect.disabled = true;
                                teacherSelect.disabled = true;
                                roomSelect.disabled = true;
                            }
                            const updateReplacementFields = () => {
                                const cancelling = actionSelect.value === 'cancelar';
                                subjectSelect.disabled = cancelling || !subjectsWithTeachers.length;
                                teacherSelect.disabled = cancelling;
                                roomSelect.disabled = cancelling;
                            };
                            actionSelect.addEventListener('change', updateReplacementFields);
                            subjectSelect.addEventListener('change', updateRelatedOptions);
                            teacherSelect.addEventListener('change', updateRelatedOptions);
                            roomSelect.addEventListener('change', updateRelatedOptions);
                            updateRelatedOptions();
                            updateReplacementFields();
                            fields.append(actionField, subjectField, teacherField, roomField);
                            if (!subjectsWithTeachers.length) {
                                const note = document.createElement('small');
                                note.className = 'muted';
                                note.textContent = 'No hay profesores disponibles para reemplazar esta clase. Solo se podrá cancelar.';
                                fields.append(note);
                            }
                            row.append(detail, fields);
                            gradeGroup.append(row);
                        }
                        categoryDetails.append(gradeGroup);
                    }
                    replacementForm.append(categoryDetails);
                }

                const actions = document.createElement('div');
                actions.className = 'actions replacement-actions';
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'btn btn-ghost';
                cancelButton.dataset.closeModal = '';
                cancelButton.textContent = 'Volver';
                const confirmCancellation = (actionSelects) => {
                    const middleClassesToCancel = actionSelects.filter((actionSelect) =>
                        actionSelect.dataset.middleClass === 'true'
                    ).length;
                    const cancellationLabel = actionSelects.length === 1
                        ? '1 clase'
                        : `${actionSelects.length} clases`;
                    const middleClassLabel = middleClassesToCancel === 1
                        ? '1 clase intermedia de la jornada'
                        : `${middleClassesToCancel} clases intermedias de la jornada`;
                    const message = middleClassesToCancel
                        ? `Se cancelarán todas las ${cancellationLabel}, incluidas ${middleClassLabel}. ¿Deseas continuar?`
                        : `Se cancelarán todas las ${cancellationLabel}. ¿Deseas continuar?`;
                    return showConfirmDialog('Confirmar cancelación', message, 'Continuar');
                };
                let cancellationConfirmed = false;
                const submitButton = document.createElement('button');
                submitButton.type = 'submit';
                submitButton.className = 'btn btn-primary';
                submitButton.textContent = 'Guardar';
                const updateSubmitButton = () => {
                    const actionSelects = [...replacementForm.querySelectorAll('select[name$="[accion]"]')];
                    const hasCancellations = actionSelects.some((actionSelect) => actionSelect.value === 'cancelar');
                    const hasReplacements = actionSelects.some((actionSelect) => actionSelect.value === 'reemplazar');
                    submitButton.textContent = hasReplacements
                        ? (hasCancellations ? 'Guardar y cancelar' : 'Guardar')
                        : 'Cancelar todo';
                };
                replacementForm.addEventListener('change', (changeEvent) => {
                    if (changeEvent.target.matches('select[name$="[accion]"]')) updateSubmitButton();
                });
                replacementForm.addEventListener('submit', async (submitEvent) => {
                    const actionSelects = [...replacementForm.querySelectorAll('select[name$="[accion]"]')];
                    if (cancellationConfirmed || !actionSelects.length
                        || actionSelects.some((actionSelect) => actionSelect.value !== 'cancelar')) return;
                    submitEvent.preventDefault();
                    if (!await confirmCancellation(actionSelects)) return;
                    cancellationConfirmed = true;
                    replacementForm.requestSubmit();
                });
                actions.append(cancelButton, submitButton);
                replacementForm.append(actions);
                updateSubmitButton();

                title.textContent = dayId
                    ? `Resolver clases del ${form.dataset.dayName || 'día seleccionado'}: ${professorName}`
                    : (professorMode === 'cancel' ? `Cancelar o reemplazar clases: ${professorName}` : `Reemplazar clases: ${professorName}`);
                body.replaceChildren(replacementForm);
                replacementForm.querySelector('select')?.focus();
            } catch (error) {
                showAlertDialog('No se pudo cargar el reemplazo', error.message);
            }
            return;
        }

        if (form.matches('[data-professor-delete-class]') && form.dataset.middleClass === 'true') {
            event.preventDefault();
            const replacementSelect = form.querySelector('[name="profesor_reemplazo"]');
            const replacementOption = replacementSelect?.selectedOptions[0];
            if (!replacementSelect?.value) {
                showAlertDialog('Profesor sustituto requerido', 'Esta clase está en medio de la jornada. Elige un profesor para reemplazarla antes de continuar.');
                return;
            }
            const replacementName = replacementOption?.textContent.trim() || 'el profesor seleccionado';
            const message = `La clase no se eliminará: se conservará en el horario con ${replacementName} como profesor sustituto. ¿Deseas continuar?`;
            if (await showConfirmDialog('Reemplazar profesor', message, 'Continuar')) {
                form.submit();
            }
            return;
        }

        if (form.matches('[data-professor-class-edit]')
            && form.dataset.middleClass === 'true'
            && form.querySelector('[name="estado"]')?.value === 'CANCELADA') {
            event.preventDefault();
            const stateSelect = form.querySelector('[name="estado"]');
            const replacementSelect = form.querySelector('[name="nuevo_profesor"]');
            const replacementOption = replacementSelect?.selectedOptions[0];
            if (!replacementSelect?.value || replacementSelect.value === form.dataset.currentTeacher) {
                showAlertDialog('Profesor sustituto requerido', 'Esta clase está en medio de la jornada. Selecciona un profesor distinto antes de cancelar.');
                return;
            }
            const replacementName = replacementOption?.textContent.trim() || 'el profesor seleccionado';
            const message = `Esta clase está en medio de la jornada. No se cancelará; quedará PROGRAMADA con ${replacementName} como profesor sustituto. ¿Deseas continuar?`;
            if (await showConfirmDialog('Reemplazar profesor', message, 'Continuar')) {
                stateSelect.value = 'PROGRAMADA';
                form.submit();
            }
            return;
        }

        if (form.matches('[data-shift-calendar-block]')) {
            const confirmedInput = form.querySelector('[name="ajustar_bloques_siguientes"]');
            if (confirmedInput?.value === '1') return;
            const endInput = form.querySelector('[name="hora_fin"]');
            const originalEnd = form.dataset.originalEnd || '';
            const newEnd = endInput?.value || '';
            const toMinutes = (value) => {
                const [hours, minutes] = value.split(':').map(Number);
                return hours * 60 + minutes;
            };
            const toTime = (value) => `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
            let followingBlocks = [];
            try {
                followingBlocks = JSON.parse(form.dataset.followingBlocks || '[]');
            } catch {
                followingBlocks = [];
            }
            const affectedBlocks = followingBlocks.filter((block) => block.hora_inicio >= originalEnd);
            const delta = originalEnd && newEnd ? toMinutes(newEnd) - toMinutes(originalEnd) : 0;
            if (delta <= 0 || !affectedBlocks.length) return;

            event.preventDefault();
            const nextBlock = affectedBlocks[0];
            const nextNumber = nextBlock.numero_bloque ? ` ${nextBlock.numero_bloque}` : '';
            const shiftedStart = toTime(toMinutes(nextBlock.hora_inicio) + delta);
            const shiftedEnd = toTime(toMinutes(nextBlock.hora_fin) + delta);
            const additionalBlocks = affectedBlocks.length - 1;
            const additionalMessage = additionalBlocks
                ? ` También se desplazarán ${additionalBlocks} bloque(s) posterior(es) para conservar el orden y su duración.`
                : '';
            const message = `Al ampliar el bloque ${form.dataset.blockNumber || ''} hasta ${newEnd}, el siguiente bloque${nextNumber} cambiará de ${nextBlock.hora_inicio}-${nextBlock.hora_fin} a ${shiftedStart}-${shiftedEnd}.${additionalMessage} ¿Desea continuar?`;
            if (!await showConfirmDialog('Ajustar bloques siguientes', message, 'Continuar')) return;
            if (confirmedInput) confirmedInput.value = '1';
            form.requestSubmit(event.submitter || undefined);
            return;
        }

        if (form.matches('[data-calendar-block-form]') && form.dataset.partitionConfirmed !== 'true') {
            const startValue = form.querySelector('[name="hora_inicio"]')?.value;
            const endValue = form.querySelector('[name="hora_fin"]')?.value;
            const blockData = JSON.parse(form.dataset.calendarBlocks || '[]');
            const toMinutes = (value) => {
                const [hours, minutes] = value.split(':').map(Number);
                return hours * 60 + minutes;
            };
            const start = toMinutes(startValue || '00:00');
            const end = toMinutes(endValue || '00:00');
            const points = new Set([start, end]);
            if (form.querySelector('[name="tipo"]')?.value === 'CLASE') {
                const siguienteClaseOcupada = blockData
                    .filter((block) => block.tipo === 'CLASE')
                    .filter((block) => toMinutes(block.inicio) < end && toMinutes(block.fin) > start)
                    .sort((left, right) => toMinutes(left.inicio) - toMinutes(right.inicio))
                    .find((block) => Number(block.diasProgramados) >= 5);
                if (siguienteClaseOcupada) {
                    event.preventDefault();
                    showAlertDialog(
                        'Horario ocupado',
                        'No se puede distribuir el horario porque la siguiente clase ocupa ese bloque todos los días.'
                    );
                    return;
                }
            }
            for (const block of blockData) {
                const blockStart = toMinutes(block.inicio);
                const blockEnd = toMinutes(block.fin);
                if (block.tipo === 'DESCANSO' && blockStart === start && end > start && end < blockEnd) {
                    points.add(blockEnd);
                }
                for (const boundary of [block.inicio, block.fin]) {
                    const minutes = toMinutes(boundary);
                    if (minutes > start && minutes < end) points.add(minutes);
                }
                if (blockStart < end && blockEnd > end) points.add(blockEnd);
            }
            const orderedPoints = [...points].sort((left, right) => left - right);
            if (startValue && endValue && end > start && orderedPoints.length > 2) {
                event.preventDefault();
                const formatTime = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                const segments = orderedPoints.slice(0, -1).map((point, index) => `${formatTime(point)}-${formatTime(orderedPoints[index + 1])}`);
                const message = `El horario se organizará en ${segments.length} bloques consecutivos: ${segments.join(', ')}. Se conservarán las clases existentes. ¿Deseas continuar?`;
                showConfirmDialog('Distribuir horario', message, 'Continuar').then((confirmed) => {
                    if (!confirmed) return;
                    form.querySelector('[name="confirmar_reparticion"]').value = '1';
                    form.dataset.partitionConfirmed = 'true';
                    form.requestSubmit();
                });
                return;
            }
        }

        const permanentSaveInput = form.querySelector('[name="guardar_permanente"]');
        if (permanentSaveInput) {
            permanentSaveInput.value = event.submitter?.matches('[data-save-permanently]') ? '1' : '0';
        }

        const stateSelect = form.querySelector('[name="estado"]');
        const blockSelect = form.querySelector('[name="id_bloque"]');
        const dateInput = form.querySelector('[name="fecha"]');
        const isPermanentDelete = event.submitter?.matches('[name="operation"][value="delete"]');
        const isPermanentCancelledSave = event.submitter?.matches('[data-save-permanently]') && stateSelect?.value === 'CANCELADA';
        const middayBreakMessage = 'Al guardar permanentemente, esta clase de media jornada se ajustará a descanso. ¿Deseas continuar?';
        const shouldSkipCancelSequenceCheck = form.dataset.skipCancelSequence === 'true';
        if (shouldSkipCancelSequenceCheck) {
            delete form.dataset.skipCancelSequence;
        }

        const isPermanentMiddayBreak = event.submitter?.matches('[data-save-permanently]')
            && stateSelect?.value === 'DESCANSO'
            && form.dataset.middleClass === 'true'
            && form.dataset.middayBreakConfirmed !== 'true';
        if (isPermanentMiddayBreak) {
            event.preventDefault();
            if (!await showConfirmDialog('Guardar como descanso', middayBreakMessage)) return;
            form.dataset.middayBreakConfirmed = 'true';
            form.requestSubmit(event.submitter);
            return;
        }

        if (!isPermanentDelete && !isPermanentCancelledSave && !shouldSkipCancelSequenceCheck && stateSelect && blockSelect && dateInput && stateSelect.value) {
            const selectedNumber = blockSelect instanceof HTMLSelectElement
                ? Number(blockSelect.selectedOptions[0]?.dataset.number || 0)
                : 0;
            const dateValue = dateInput.value;
            const table = document.querySelector('.schedule');

            if (table && dateValue && selectedNumber > 0) {
                const [year, month, day] = dateValue.split('-').map(Number);
                const jsDay = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
                const dayIndex = ((jsDay + 6) % 7) + 1;
                const rows = Array.from(table.querySelectorAll('tbody tr'));

                let tieneCanceladasPosterioresConsecutivas = false;
                let cadenaPosteriorContinua = true;

                for (const row of rows) {
                    const cells = Array.from(row.querySelectorAll('td'));
                    const blockNumber = Number(cells[0]?.textContent.match(/Bloque\s+(\d+)/)?.[1] || 0);
                    if (!blockNumber || cells.length <= dayIndex) continue;

                    const classBlock = cells[dayIndex]?.querySelector('.cell-class');
                    if (!classBlock) continue;
                    const isCancelled = classBlock.classList.contains('is-cancelled') || classBlock.textContent.includes('CANCELADA');

                    if (blockNumber > selectedNumber && cadenaPosteriorContinua) {
                        if (isCancelled) {
                            tieneCanceladasPosterioresConsecutivas = true;
                        } else {
                            cadenaPosteriorContinua = false;
                        }
                    }
                }

                if (stateSelect.value === 'PROGRAMADA' && permanentSaveInput?.value === '1' && tieneCanceladasPosterioresConsecutivas) {
                    event.preventDefault();
                    const message = '¿También deseas programar las clases canceladas consecutivas que siguen a esta clase, o prefieres dejarlas canceladas?';
                    const submitter = event.submitter;
                    showConfirmDialog('Clases siguientes', message, 'Programar las siguientes', 'Dejarlas canceladas').then((reprogramar) => {
                        form.querySelector('[name="reprogramar_siguientes"]').value = reprogramar ? '1' : '0';
                        form.dataset.skipCancelSequence = 'true';
                        if (submitter instanceof HTMLElement) {
                            form.requestSubmit(submitter);
                        } else {
                            form.requestSubmit();
                        }
                    });
                    return;
                }
            }
        }

        const isBreakReplacementWarning = Boolean(
            stateSelect
            && form.querySelector('[name="reemplazo_descanso"]') !== null
            && stateSelect.value === 'PROGRAMADA'
        );
        const isBreakAdditionWarning = Boolean(
            stateSelect
            && (form.dataset.breakAddition === 'true' || form.querySelector('[name="novedad_descanso"]') !== null)
            && stateSelect.value === 'PROGRAMADA'
        );
        const isPermanentBreakConversion = isBreakAdditionWarning && permanentSaveInput?.value === '1';
        const message = isBreakReplacementWarning
                ? 'Se guardará este reemplazo de descanso en estado PROGRAMADA. ¿Desea continuar?'
                : isBreakAdditionWarning
                    ? (isPermanentBreakConversion
                        ? 'La clase reemplazará este descanso cada semana para este grado. Los demás días conservarán el descanso y los otros grados no tendrán cambios. ¿Desea continuar?'
                        : 'La clase se guardará como novedad para este descanso y no modificará el horario semanal. ¿Desea continuar?')
                : form.dataset.middayBreakConfirmed === 'true' && event.submitter?.matches('[data-save-permanently]')
                    ? ''
                    : isPermanentCancelledSave
                        ? 'La clase se cancelará permanentemente y se quitará del horario semanal. ¿Desea continuar?'
                        : event.submitter?.dataset.confirm || form.dataset.confirm;
        if (!message) return;

        event.preventDefault();
        const confirmed = await showConfirmDialog('Confirmar acción', message);
        if (confirmed) {
            if (event.submitter?.name) {
                const submitterInput = document.createElement('input');
                submitterInput.type = 'hidden';
                submitterInput.name = event.submitter.name;
                submitterInput.value = event.submitter.value;
                form.append(submitterInput);
            }
            form.submit();
        }
    });

    document.addEventListener('click', async (event) => {
        const deleteMaterialButton = event.target.closest('[data-delete-material] button[type="submit"]');
        if (deleteMaterialButton) {
            const form = deleteMaterialButton.closest('form[data-delete-material]');
            if (!form) return;
            event.preventDefault();
            const materiaId = form.querySelector('[name="id_materia"]')?.value;
            const materiaName = form.dataset.materiaName || 'esta materia';
            if (!materiaId) return;
            const url = new URL(form.dataset.replacementUrl || `${window.location.origin}${window.location.pathname}?api=clases_materia`, window.location.href);
            url.searchParams.set('id_materia', materiaId);
            url.searchParams.set('api', 'clases_materia');
            try {
                const response = await fetch(url.toString());
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'No se pudo consultar las clases de la materia.');
                if (!data.clases || !data.clases.length) {
                    const confirmed = await showConfirmDialog('Confirmar eliminación', `¿Está seguro de que desea eliminar la materia ${materiaName}?`);
                    if (confirmed) form.submit();
                    return;
                }
                const confirmed = await showConfirmDialog(
                    'Reasignar antes de eliminar',
                    `Debe reasignar o cancelar todas las clases de ${materiaName}. Se detectaron ${data.clases.length} clases asociadas. Elija una materia para reemplazarlas o cancelarlas antes de borrar la materia.`
                );
                if (!confirmed) return;

                const replacementForm = document.createElement('form');
                replacementForm.method = 'post';
                replacementForm.append(form.querySelector('input[name="csrf"]')?.cloneNode(true));
                const addHidden = (name, value) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = String(value);
                    replacementForm.append(input);
                };
                addHidden('action', 'delete_subject_with_replacements');
                addHidden('id_materia', materiaId);

                const options = Array.from(form.querySelector('[data-replacement-subjects]')?.options || []).filter((option) => option.value && option.value !== materiaId);
                const subjectCatalog = options.map((option) => ({ id: option.value, nombre: option.textContent.trim() }));
                const classesByGrade = new Map();
                for (const classItem of data.clases) {
                    if (!classesByGrade.has(classItem.id_grado)) classesByGrade.set(classItem.id_grado, []);
                    classesByGrade.get(classItem.id_grado).push(classItem);
                }
                for (const [grade, gradeClasses] of classesByGrade) {
                    const gradeDetails = document.createElement('details');
                    gradeDetails.open = true;
                    const summary = document.createElement('summary');
                    summary.textContent = `${grade} · ${gradeClasses.length} clase(s)`;
                    gradeDetails.append(summary);
                    for (const classItem of gradeClasses) {
                        const row = document.createElement('div');
                        row.className = 'replacement-class-row';
                        const detail = document.createElement('p');
                        detail.className = 'replacement-class-detail';
                        detail.textContent = `${classItem.dia} · ${classItem.hora_inicio}-${classItem.hora_fin} · ${classItem.profesor || 'Sin profesor'} · ${classItem.salon || 'Sin salón'}`;
                        const fields = document.createElement('div');
                        fields.className = 'replacement-class-fields';

                        const actionField = document.createElement('div');
                        actionField.className = 'field';
                        const actionLabel = document.createElement('label');
                        actionLabel.textContent = 'Acción';
                        const actionSelect = document.createElement('select');
                        actionSelect.name = `reemplazos[${classItem.id_horario}][accion]`;
                        actionSelect.append(new Option('Reemplazar', 'reemplazar'), new Option('Cancelar clase', 'cancelar'));
                        actionField.append(actionLabel, actionSelect);

                        const subjectField = document.createElement('div');
                        subjectField.className = 'field';
                        const subjectLabel = document.createElement('label');
                        subjectLabel.textContent = 'Materia sustituta';
                        const subjectSelect = document.createElement('select');
                        subjectSelect.name = `reemplazos[${classItem.id_horario}][materia]`;
                        subjectSelect.required = true;
                        subjectSelect.append(new Option('Seleccione una materia', ''));
                        subjectCatalog.forEach((subject) => subjectSelect.add(new Option(subject.nombre, subject.id)));
                        subjectSelect.value = subjectCatalog[0]?.id || '';
                        subjectField.append(subjectLabel, subjectSelect);

                        const canReplace = subjectCatalog.length > 0;
                        if (!canReplace) {
                            actionSelect.options[0].disabled = true;
                            actionSelect.value = 'cancelar';
                        }
                        const updateSubjectAvailability = () => {
                            const cancelling = actionSelect.value === 'cancelar';
                            subjectSelect.disabled = cancelling || !canReplace;
                            subjectSelect.required = !cancelling && canReplace;
                        };
                        actionSelect.addEventListener('change', updateSubjectAvailability);
                        updateSubjectAvailability();
                        fields.append(actionField, subjectField);
                        row.append(detail, fields);
                        gradeDetails.append(row);
                    }
                    replacementForm.append(gradeDetails);
                }

                const actions = document.createElement('div');
                actions.className = 'actions replacement-actions';
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'btn btn-ghost';
                cancelButton.dataset.closeModal = '';
                cancelButton.textContent = 'Cancelar';
                const submitButton = document.createElement('button');
                submitButton.type = 'submit';
                submitButton.className = 'btn btn-primary';
                submitButton.textContent = 'Confirmar reemplazos';
                actions.append(cancelButton, submitButton);
                replacementForm.append(actions);

                title.textContent = `Eliminar materia: ${materiaName}`;
                body.replaceChildren(replacementForm);
                modal.classList.add('open');
                replacementForm.querySelector('select')?.focus();
                return;
            } catch (error) {
                showAlertDialog('No se pudo cargar el reemplazo', error.message);
            }
            return;
        }

        const toggleProfessorList = event.target.closest('[data-toggle-professor-list]');
        if (toggleProfessorList) {
            const form = toggleProfessorList.closest('form');
            const select = form?.querySelector('[data-professor-mode-select]');
            if (!select) return;

            const showingAssigned = toggleProfessorList.dataset.showingAssigned !== 'true';
            for (const option of select.options) {
                if (!option.value || option.value === '0') continue;
                const assigned = option.dataset.subjectAssigned === '1' || option.dataset.roomAssigned === '1';
                option.hidden = showingAssigned ? !assigned : assigned;
                option.disabled = showingAssigned ? !assigned : assigned;
            }
            if (select.selectedOptions[0]?.disabled) select.value = '0';
            toggleProfessorList.dataset.showingAssigned = String(showingAssigned);
            toggleProfessorList.textContent = showingAssigned
                ? (toggleProfessorList.dataset.availableLabel || 'Todos los profesores')
                : (toggleProfessorList.dataset.assignedLabel || 'Profesores de materia');

            const removeButtons = form.querySelectorAll('[data-clear-select="id_profesor"]');
            if (removeButtons.length) {
                const hasAssignedProfessors = Array.from(select.options).some(
                    (option) => option.dataset.subjectAssigned === '1' || option.dataset.roomAssigned === '1'
                );
                const selectedOption = select.selectedOptions[0];
                const hasCurrentClasses = selectedOption?.dataset.subjectCurrent === '1' || selectedOption?.dataset.roomCurrent === '1';
                const hasPermanentLink = selectedOption?.dataset.subjectPermanent === '1' || selectedOption?.dataset.roomPermanent === '1';
                removeButtons.forEach((button) => {
                    const canRemove = hasCurrentClasses || hasPermanentLink;
                    button.hidden = !showingAssigned || !hasAssignedProfessors;
                    button.disabled = !showingAssigned || !hasAssignedProfessors || !canRemove;
                });
            }
            return;
        }

        const clearButton = event.target.closest('[data-clear-select]');
        if (!clearButton) return;
        const form = clearButton.closest('form');
        if (!form) return;
        const select = form.querySelector(`select[name="${clearButton.dataset.clearSelect}"]`);
        if (!select) return;
        const selectedOption = select.selectedOptions[0];
        if (!selectedOption || !selectedOption.value || selectedOption.value === '0') return;

        if (clearButton.hasAttribute('data-replacement-workflow')) {
            const deletePermanent = clearButton.hasAttribute('data-delete-permanent');
            const subjectSelect = form.querySelector('select[name="nombre"]');
            const subjectName = subjectSelect?.selectedOptions[0]?.textContent.trim() || 'esta materia';
            const materiaId = form.querySelector('[name="id_materia"]')?.value;
            const url = new URL(form.dataset.professorReplacementUrl, window.location.href);
            url.searchParams.set('id_materia', materiaId || '');
            url.searchParams.set('id_profesor', selectedOption.value);

            try {
                const response = await fetch(url.toString());
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'No se pudieron consultar las clases del profesor.');
                if (!form.isConnected) return;

                if (!data.clases.length) {
                    const confirmed = await showConfirmDialog(
                        'Eliminar profesor',
                        `El profesor ${selectedOption.textContent.trim()} no tiene clases programadas. Se eliminará su vínculo con la materia ${subjectName}.`
                    );
                    if (!confirmed) return;
                    const hiddenTeacher = form.querySelector('input[name="profesor_a_quitar"]');
                    const permanentInput = form.querySelector('[name="eliminar_permanente"]');
                    if (hiddenTeacher) hiddenTeacher.value = selectedOption.value;
                    if (permanentInput) permanentInput.value = '1';
                    select.value = '0';
                    form.requestSubmit();
                    return;
                }

                const warning = `Se reemplazará a ${selectedOption.textContent.trim()} únicamente en ${data.clases.length} clase(s) programada(s) de esta materia.`;
                if (!await showConfirmDialog('Reemplazar profesor', warning)) return;
                if (!form.isConnected) return;

                const replacementSubjectsField = form.querySelector('[data-replacement-subjects]');
                const replacementProfessorsField = form.querySelector('[data-replacement-professors]');
                const csrfField = form.querySelector('input[name="csrf"]');
                if (!replacementSubjectsField || !replacementProfessorsField || !csrfField) {
                    throw new Error('Falta la configuración del reemplazo para esta materia.');
                }

                const replacementForm = document.createElement('form');
                replacementForm.method = 'post';
                replacementForm.append(csrfField.cloneNode(true));
                const addHidden = (name, value) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = String(value ?? '');
                    replacementForm.append(input);
                };
                addHidden('action', 'replace_professor');
                addHidden('id_materia', materiaId || '');
                addHidden('nombre', subjectSelect?.value || form.querySelector('[name="nombre"]')?.value || '');
                addHidden('activa', form.querySelector('[name="activa"]')?.value || '1');
                addHidden('profesor_a_quitar', selectedOption.value);
                addHidden('eliminar_permanente', deletePermanent ? '1' : '0');

                const classesByGrade = new Map();
                for (const classItem of data.clases) {
                    if (!classesByGrade.has(classItem.id_grado)) classesByGrade.set(classItem.id_grado, []);
                    classesByGrade.get(classItem.id_grado).push(classItem);
                }
                const selectors = [];
                const subjectCatalog = Array.from(replacementSubjectsField.options)
                    .map((option) => ({
                        id: option.value,
                        nombre: option.textContent,
                        salones: new Set((option.dataset.rooms || '').split(',').filter(Boolean)),
                    }));
                const teacherCatalog = Array.from(replacementProfessorsField.options)
                    .map((option) => ({
                        id: option.value,
                        nombre: option.textContent,
                        materias: new Set((option.dataset.subjects || '').split(',').filter(Boolean)),
                        conteos: new Map((option.dataset.teacherCounts || '').split(',').filter(Boolean).map((entry) => {
                            const [materiaId, total] = entry.split(':');
                            return [materiaId, Number(total || 0)];
                        })),
                        roomCounts: new Map((option.dataset.roomCounts || '').split(',').filter(Boolean).map((entry) => {
                            const [roomId, total] = entry.split(':');
                            return [String(roomId), Number(total || 0)];
                        })),
                        salones: new Set((option.dataset.rooms || '').split(',').filter(Boolean)),
                    }));
                subjectCatalog.forEach((subject) => {
                    subject.teachers = new Set(teacherCatalog
                        .filter((teacher) => teacher.materias.has(subject.id))
                        .map((teacher) => teacher.id));
                });
                const commonPanel = document.createElement('section');
                commonPanel.className = 'replacement-common-panel';
                const commonTitle = document.createElement('strong');
                commonTitle.textContent = 'Profesor disponible en todos los horarios';
                const commonStatus = document.createElement('p');
                commonStatus.textContent = 'Comparando disponibilidad…';
                const commonSelect = document.createElement('select');
                commonSelect.setAttribute('aria-label', 'Profesor disponible en todos los horarios');
                commonSelect.append(new Option('Consultando disponibilidad…', ''));
                const applyCommonButton = document.createElement('button');
                applyCommonButton.type = 'button';
                applyCommonButton.className = 'btn btn-secondary';
                applyCommonButton.disabled = true;
                applyCommonButton.textContent = 'Usar en todas las clases';
                commonPanel.append(commonTitle, commonStatus, commonSelect, applyCommonButton);
                replacementForm.append(commonPanel);

                for (const [grade, gradeClasses] of classesByGrade) {
                    const gradeDetails = document.createElement('details');
                    gradeDetails.className = 'replacement-grade';
                    const summary = document.createElement('summary');
                    summary.textContent = `${grade} · ${gradeClasses.length} clase(s)`;
                    gradeDetails.append(summary);

                    for (const classItem of gradeClasses) {
                        const classRow = document.createElement('div');
                        classRow.className = 'replacement-class-row';
                        const detail = document.createElement('p');
                        detail.className = 'replacement-class-detail';
                        detail.textContent = `${classItem.dia} · ${classItem.hora_inicio}-${classItem.hora_fin} · ${classItem.salon}`;
                        const fields = document.createElement('div');
                        fields.className = 'replacement-class-fields';
                        const actionField = document.createElement('div');
                        actionField.className = 'field';
                        const actionLabel = document.createElement('label');
                        actionLabel.textContent = 'Acción';
                        const actionSelect = document.createElement('select');
                        actionSelect.name = `reemplazos[${classItem.id_horario}][accion]`;
                        const replaceOption = new Option('Reemplazar', 'reemplazar');
                        const cancelOption = new Option('Cancelar clase', 'cancelar');
                        actionSelect.append(replaceOption, cancelOption);
                        actionSelect.value = 'cancelar';
                        actionField.append(actionLabel, actionSelect);

                        const subjectField = document.createElement('div');
                        subjectField.className = 'field';
                        const subjectLabel = document.createElement('label');
                        subjectLabel.textContent = 'Materia';
                        const subjectSelect = document.createElement('select');
                        subjectSelect.name = `reemplazos[${classItem.id_horario}][materia]`;
                        subjectSelect.required = true;
                        subjectCatalog.forEach((subject) => subjectSelect.add(new Option(subject.nombre, subject.id)));
                        subjectSelect.value = String(classItem.id_materia);
                        subjectField.append(subjectLabel, subjectSelect);

                        const professorField = document.createElement('div');
                        professorField.className = 'field';
                        const professorLabel = document.createElement('label');
                        professorLabel.textContent = 'Profesor disponible';
                        const professorSelect = document.createElement('select');
                        professorSelect.name = `reemplazos[${classItem.id_horario}][profesor]`;
                        professorSelect.required = true;
                        professorSelect.append(new Option('Consultando disponibilidad…', ''));

                        const roomField = document.createElement('div');
                        roomField.className = 'field';
                        const roomLabel = document.createElement('label');
                        roomLabel.textContent = 'Salón';
                        const roomSelect = document.createElement('select');
                        roomSelect.name = `reemplazos[${classItem.id_horario}][salon]`;
                        roomSelect.dataset.searchableRoom = '1';
                        roomSelect.append(new Option('Sin salón', ''));

                        const availabilityStatus = document.createElement('small');
                        availabilityStatus.className = 'replacement-availability muted';
                        availabilityStatus.textContent = '';
                        subjectField.append(subjectLabel, subjectSelect);
                        professorField.append(professorLabel, professorSelect, availabilityStatus);
                        roomField.append(roomLabel, roomSelect);
                        fields.append(actionField, subjectField, professorField, roomField);
                        classRow.append(detail, fields);
                        gradeDetails.append(classRow);
                        selectors.push({
                            classItem,
                            actionSelect,
                            subjectSelect,
                            professorSelect,
                            roomSelect,
                            availabilityStatus,
                            availableTeachers: [],
                            availableRooms: [],
                            initialRoom: String(classItem.id_salon || ''),
                            availabilityFailed: false,
                        });
                    }
                    replacementForm.append(gradeDetails);
                }

                const actions = document.createElement('div');
                actions.className = 'actions replacement-actions';
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'btn btn-ghost';
                cancelButton.dataset.closeModal = '';
                cancelButton.textContent = 'Cancelar';
                const saveButton = document.createElement('button');
                saveButton.type = 'submit';
                saveButton.className = 'btn btn-primary';
                saveButton.disabled = true;
                saveButton.textContent = 'Guardar y cancelar';
                actions.append(cancelButton, saveButton);
                replacementForm.append(actions);

                const chooseSubjectForProfessor = (entry, professorId) => {
                    const teacher = teacherCatalog.find((candidate) => candidate.id === String(professorId));
                    if (!teacher) return;
                    const teacherSubjects = subjectCatalog
                        .filter((subject) => teacher.materias.has(subject.id))
                        .sort((first, second) => {
                            const firstCount = teacher.conteos.get(first.id) || 0;
                            const secondCount = teacher.conteos.get(second.id) || 0;
                            if (secondCount !== firstCount) return secondCount - firstCount;
                            return first.nombre.localeCompare(second.nombre, 'es', {sensitivity: 'base'});
                        });
                    const selectedSubject = teacherSubjects[0];
                    if (!selectedSubject) return;
                    if (entry.subjectSelect.value !== selectedSubject.id) {
                        entry.subjectSelect.value = selectedSubject.id;
                        entry.subjectSelect.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                };
                const chooseSalonForProfessor = (entry, professorId) => {
                    const teacher = teacherCatalog.find((candidate) => candidate.id === String(professorId));
                    const subject = subjectCatalog.find((candidate) => candidate.id === entry.subjectSelect.value);
                    const teacherRooms = teacher?.salones || new Set();
                    const subjectRooms = subject?.salones || new Set();
                    const roomCounts = teacher?.roomCounts || new Map();
                    const availableRooms = entry.availableRooms;
                    const roomScore = (room) => {
                        const roomId = String(room.id);
                        return (roomCounts.get(roomId) || 0) * 100 + (teacherRooms.has(roomId) ? 10 : 0) + (subjectRooms.has(roomId) ? 5 : 0);
                    };
                    const sharedRooms = availableRooms.filter((room) => (
                        teacherRooms.has(String(room.id)) && subjectRooms.has(String(room.id))
                    )).sort((first, second) => roomScore(second) - roomScore(first));
                    const teacherRoomsAvailable = availableRooms.filter((room) => teacherRooms.has(String(room.id))).sort((first, second) => roomScore(second) - roomScore(first));
                    const subjectRoomsAvailable = availableRooms.filter((room) => subjectRooms.has(String(room.id))).sort((first, second) => roomScore(second) - roomScore(first));
                    const preferredRooms = sharedRooms.length
                        ? sharedRooms
                        : teacherRoomsAvailable.length ? teacherRoomsAvailable
                            : subjectRoomsAvailable.length ? subjectRoomsAvailable : availableRooms;
                    if (!preferredRooms.length) {
                        if (entry.roomSelect.value) {
                            entry.roomSelect.value = '';
                            entry.roomSelect.dispatchEvent(new Event('change', {bubbles: true}));
                        }
                        return;
                    }
                    const selectedRoomId = String(preferredRooms[0].id);
                    if (entry.roomSelect.value !== selectedRoomId) {
                        entry.roomSelect.value = selectedRoomId;
                        entry.roomSelect.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                };
                const renderGroupedOptions = (select, placeholderText, groups, selectedValue) => {
                    select.dataset.searchGroups = JSON.stringify(groups.map(({label}) => label).filter(Boolean));
                    select.replaceChildren(new Option(placeholderText, ''));
                    groups.forEach(({label, options}) => {
                        if (!options.length) return;
                        const group = document.createElement('optgroup');
                        group.label = label;
                        options.forEach((option) => {
                            const optionElement = new Option(option.nombre, option.id);
                            optionElement.dataset.searchGroup = label;
                            group.append(optionElement);
                        });
                        select.append(group);
                    });
                    if (selectedValue && Array.from(select.options).some((option) => option.value === selectedValue)) {
                        select.value = selectedValue;
                    } else {
                        select.value = '';
                    }
                };
                const populateAvailableSubjects = (entry) => {
                    const selectedValue = entry.subjectSelect.value;
                    const teacherId = entry.professorSelect.value;
                    const teacher = teacherCatalog.find((candidate) => candidate.id === teacherId);
                    const roomId = entry.roomSelect.value;
                    const room = entry.availableRooms.find((candidate) => String(candidate.id) === roomId);
                    const teacherName = teacher?.nombre || '';
                    const roomName = room?.nombre || '';
                    const availableTeacherIds = new Set(entry.availableTeachers.map((candidate) => String(candidate.id)));

                    const subjectMatchesTeacherAvailability = (subject) => {
                        if (!teacherId) {
                            return teacherCatalog.some((candidate) =>
                                availableTeacherIds.has(String(candidate.id)) &&
                                candidate.materias.has(subject.id)
                            );
                        }
                        return teacher?.materias.has(subject.id) && availableTeacherIds.has(String(teacher.id));
                    };

                    const availableSubjects = subjectCatalog.filter((subject) => {
                        if (!subjectMatchesTeacherAvailability(subject)) return false;
                        if (roomId && !subject.salones.has(roomId)) return false;
                        return true;
                    });

                    const teacherCounts = teacherId
                        ? teacherCatalog.find((candidate) => candidate.id === teacherId)?.conteos || new Map()
                        : new Map();
                    const sortedAvailableSubjects = [...availableSubjects].sort((first, second) => {
                        const firstCount = teacherId ? teacherCounts.get(first.id) || 0 : 0;
                        const secondCount = teacherId ? teacherCounts.get(second.id) || 0 : 0;
                        if (secondCount !== firstCount) return secondCount - firstCount;
                        return first.nombre.localeCompare(second.nombre, 'es', {sensitivity: 'base'});
                    });

                    const groupedOptions = [
                        {
                            label: roomId ? `Materias de ${roomName}` : '',
                            options: sortedAvailableSubjects.filter((subject) => roomId && subject.salones.has(roomId)),
                        },
                        {
                            label: teacherId ? `Materias de ${teacherName}` : 'Materias de profesores libres',
                            options: sortedAvailableSubjects.filter((subject) => teacherId ? subject.teachers.has(teacherId) : true),
                        },
                        {
                            label: 'Otros',
                            options: subjectCatalog.filter((subject) => {
                                if (!subjectMatchesTeacherAvailability(subject)) return false;
                                if (roomId && subject.salones.has(roomId)) return false;
                                if (teacherId && subject.teachers.has(teacherId)) return false;
                                return true;
                            }).sort((first, second) => first.nombre.localeCompare(second.nombre, 'es', {sensitivity: 'base'})),
                        },
                    ];
                    renderGroupedOptions(entry.subjectSelect,
                        'Seleccione una materia',
                        groupedOptions,
                        selectedValue
                    );
                    if (teacherId && !selectedValue && sortedAvailableSubjects.length) {
                        entry.subjectSelect.value = sortedAvailableSubjects[0].id;
                    }
                    if (!availableSubjects.length) {
                        entry.subjectSelect.value = '';
                    }
                    entry.subjectSelect.disabled = availableSubjects.length === 0;
                };
                const populateAvailableProfessors = (entry) => {
                    const selectedValue = entry.professorSelect.value;
                    const roomId = entry.roomSelect.value;
                    const room = entry.availableRooms.find((candidate) => String(candidate.id) === roomId);
                    const subjectId = entry.subjectSelect.value;
                    const subject = subjectCatalog.find((candidate) => candidate.id === subjectId);
                    const availableTeachers = entry.availableTeachers;
                    const professorGroups = [
                        {
                            label: roomId ? `Profesores de ${room?.nombre || ''}` : '',
                            options: availableTeachers.filter((teacher) => roomId && teacher.salones.has(roomId)),
                        },
                        {
                            label: subjectId ? `Profesores de ${subject?.nombre || ''}` : '',
                            options: availableTeachers.filter((teacher) => subjectId && teacher.materias.has(subjectId)),
                        },
                        {
                            label: 'Otros',
                            options: availableTeachers.filter((teacher) => (
                                (!roomId || !teacher.salones.has(roomId))
                                && (!subjectId || !teacher.materias.has(subjectId))
                            )),
                        },
                    ];
                    renderGroupedOptions(entry.professorSelect,
                        availableTeachers.length ? 'Seleccione un profesor' : 'No hay profesores libres en el horario de esta clase',
                        professorGroups, selectedValue);
                    entry.professorSelect.disabled = availableTeachers.length === 0;
                    entry.availabilityStatus.textContent = '';
                    if (!availableTeachers.length) entry.availabilityStatus.dataset.state = 'unavailable';
                    else delete entry.availabilityStatus.dataset.state;
                };
                const populateAvailableRooms = (entry) => {
                    const selectedValue = entry.roomSelect.value || entry.initialRoom;
                    const teacherId = entry.professorSelect.value;
                    const teacher = teacherCatalog.find((candidate) => candidate.id === teacherId);
                    const subjectId = entry.subjectSelect.value;
                    const subject = subjectCatalog.find((candidate) => candidate.id === subjectId);
                    const availableRooms = entry.availableRooms;
                    renderGroupedOptions(entry.roomSelect, 'Sin salón', [
                        {
                            label: teacherId ? `Salones de ${teacher?.nombre || ''}` : '',
                            options: availableRooms.filter((room) => teacher?.salones.has(String(room.id))),
                        },
                        {
                            label: subjectId ? `Salones de ${subject?.nombre || ''}` : '',
                            options: availableRooms.filter((room) => subject?.salones.has(String(room.id))),
                        },
                        {
                            label: 'Otros',
                            options: availableRooms.filter((room) => subjectId
                                ? !subject?.salones.has(String(room.id))
                                : teacherId ? !teacher?.salones.has(String(room.id)) : true),
                        },
                    ], selectedValue);
                    entry.initialRoom = '';
                };
                const refreshRelatedOptions = (entry) => {
                    if (entry.actionSelect.value === 'cancelar') return;
                    populateAvailableSubjects(entry);
                    populateAvailableProfessors(entry);
                    populateAvailableRooms(entry);
                };
                const updateSaveState = () => {
                    saveButton.textContent = selectors.some(({actionSelect}) => actionSelect.value === 'cancelar')
                        ? 'Guardar y cancelar'
                        : 'Guardar';
                    saveButton.disabled = selectors.some(({actionSelect, subjectSelect, professorSelect, roomSelect, availableTeachers, availableRooms}) => {
                        if (actionSelect.value === 'cancelar') return false;
                        return !subjectSelect.value || !professorSelect.value || professorSelect.disabled
                            || (roomSelect.required && !roomSelect.value)
                            || (availableTeachers.length === 0 && !professorSelect.disabled)
                            || (availableRooms.length === 0 && !roomSelect.disabled);
                    });
                };
                const refreshCommonTeachers = () => {
                    const commonTeachers = selectors.length
                        ? selectors[0].availableTeachers.filter((teacher) => selectors.every((entry) =>
                            entry.availableTeachers.some((candidate) => candidate.id === teacher.id)
                        ))
                        : [];
                    commonSelect.replaceChildren(new Option(
                        commonTeachers.length ? 'Selecciona un profesor común' : 'No hay coincidencias en todos los horarios',
                        ''
                    ));
                    commonTeachers.forEach((teacher) => commonSelect.add(new Option(teacher.nombre, teacher.id)));
                    commonSelect.disabled = commonTeachers.length === 0;
                    applyCommonButton.disabled = commonTeachers.length === 0 || !commonSelect.value;
                    commonStatus.textContent = '';
                    if (!commonTeachers.length) commonStatus.dataset.state = 'unavailable';
                    else delete commonStatus.dataset.state;
                };
                selectors.forEach((entry) => {
                    entry.actionSelect.addEventListener('change', () => {
                        const cancelling = entry.actionSelect.value === 'cancelar';
                        entry.subjectSelect.disabled = cancelling;
                        entry.professorSelect.disabled = cancelling;
                        entry.roomSelect.disabled = cancelling;
                        if (cancelling) {
                            entry.subjectSelect.value = '';
                            entry.professorSelect.value = '';
                            entry.roomSelect.value = '';
                        }
                        refreshCommonTeachers();
                        updateSaveState();
                    });
                    entry.subjectSelect.addEventListener('change', () => {
                        if (entry.actionSelect.value === 'cancelar') return;
                        if (entry.professorSelect.value) chooseSalonForProfessor(entry, entry.professorSelect.value);
                        refreshRelatedOptions(entry);
                        refreshCommonTeachers();
                        updateSaveState();
                    });
                    entry.professorSelect.addEventListener('change', () => {
                        if (entry.actionSelect.value === 'cancelar') return;
                        if (entry.professorSelect.value) {
                            chooseSubjectForProfessor(entry, entry.professorSelect.value);
                            chooseSalonForProfessor(entry, entry.professorSelect.value);
                        }
                        refreshRelatedOptions(entry);
                        refreshCommonTeachers();
                        updateSaveState();
                    });
                    entry.roomSelect.addEventListener('change', () => {
                        if (entry.actionSelect.value === 'cancelar') return;
                        refreshRelatedOptions(entry);
                        refreshCommonTeachers();
                        updateSaveState();
                    });
                    entry.actionSelect.dispatchEvent(new Event('change', {bubbles: true}));
                });
                commonSelect.addEventListener('change', () => {
                    applyCommonButton.disabled = !commonSelect.value;
                });
                applyCommonButton.addEventListener('click', () => {
                    const commonTeacherId = commonSelect.value;
                    if (!commonTeacherId) return;
                    selectors.forEach((entry) => {
                        entry.actionSelect.value = 'reemplazar';
                        entry.actionSelect.dispatchEvent(new Event('change', {bubbles: true}));
                        entry.professorSelect.value = commonTeacherId;
                        chooseSubjectForProfessor(entry, commonTeacherId);
                        chooseSalonForProfessor(entry, commonTeacherId);
                        refreshRelatedOptions(entry);
                    });
                    refreshCommonTeachers();
                    updateSaveState();
                });

                await Promise.all(selectors.map(async (entry) => {
                    const {classItem, professorSelect, roomSelect, availabilityStatus} = entry;
                    const params = new URLSearchParams({
                        dia: String(classItem.id_dia),
                        id_bloque: String(classItem.id_bloque),
                        id_horario: String(classItem.id_horario),
                    });
                    try {
                        const availabilityResponse = await fetch(`${form.dataset.teacherAvailabilityUrl}&${params.toString()}`);
                        const availability = await availabilityResponse.json();
                        if (!availabilityResponse.ok) throw new Error(availability.error || 'No se pudo consultar disponibilidad.');
                        entry.availableTeachers = availability.profesores
                            .filter((teacher) => teacher.disponible && String(teacher.id) !== String(selectedOption.value))
                            .map((teacher) => {
                                const teacherInfo = teacherCatalog.find((candidate) => candidate.id === String(teacher.id));
                                return {
                                    id: String(teacher.id),
                                    nombre: teacher.nombre,
                                    materias: teacherInfo?.materias || new Set(),
                                    salones: teacherInfo?.salones || new Set(),
                                };
                            });
                        entry.availableRooms = availability.salones.filter((room) => room.disponible);
                        refreshRelatedOptions(entry);
                        return entry.availableTeachers;
                    } catch (error) {
                        entry.availabilityFailed = true;
                        professorSelect.replaceChildren(new Option('No se pudo consultar disponibilidad', ''));
                        professorSelect.disabled = true;
                        roomSelect.replaceChildren(new Option('No se pudo consultar disponibilidad', ''));
                        roomSelect.disabled = true;
                        availabilityStatus.textContent = error.message;
                        availabilityStatus.dataset.state = 'unavailable';
                        return null;
                    }
                }));

                selectors.forEach((entry) => {
                    if (!entry.availabilityFailed) refreshRelatedOptions(entry);
                });
                refreshCommonTeachers();
                updateSaveState();

                title.textContent = `Reemplazar a ${selectedOption.textContent.trim()}`;
                body.replaceChildren(replacementForm);
                modal.classList.add('open');
                replacementForm.querySelector('select')?.focus();
            } catch (error) {
                showAlertDialog('No se pudo cargar el reemplazo', error.message);
            }
            return;
        }

        const deletePermanent = clearButton.hasAttribute('data-delete-permanent');
        const teacherName = selectedOption.textContent.trim();
        const subjectSelect = form.querySelector('select[name="nombre"]');
        const subjectName = subjectSelect?.selectedOptions[0]?.textContent.trim() || 'este registro';
        const resourceLabel = clearButton.dataset.resourceLabel || 'materia';
        const confirmed = await showConfirmDialog(
            'Confirmar eliminación',
            deletePermanent
                ? `¿Desea quitar a ${teacherName} de las clases actuales de ${resourceLabel} ${subjectName} y eliminar también el vínculo permanente para futuras clases?`
                : `¿Desea quitar a ${teacherName} de las clases actuales de ${resourceLabel} ${subjectName}? Se conservará el vínculo permanente para las futuras clases.`
        );
        if (!confirmed) return;

        const hiddenTeacher = form.querySelector('input[name="profesor_a_quitar"]');
        if (hiddenTeacher) {
            hiddenTeacher.value = selectedOption.value;
        }
        const permanentInput = form.querySelector('[name="eliminar_permanente"]');
        if (permanentInput) permanentInput.value = deletePermanent ? '1' : '0';
        select.value = '0';
        form.requestSubmit();
    });

    document.addEventListener('change', (event) => {
        const select = event.target.closest('[data-professor-mode-select]');
        if (!select) return;
        const form = select.closest('form');
        const toggle = form?.querySelector('[data-toggle-professor-list]');
        const showingAssigned = toggle?.dataset.showingAssigned === 'true';
        const selectedOption = select.selectedOptions[0];
        const hasAssignedProfessors = Array.from(select.options).some(
            (option) => option.dataset.subjectAssigned === '1' || option.dataset.roomAssigned === '1'
        );
        const hasCurrentClasses = selectedOption?.dataset.subjectCurrent === '1' || selectedOption?.dataset.roomCurrent === '1';
        const hasPermanentLink = selectedOption?.dataset.subjectPermanent === '1' || selectedOption?.dataset.roomPermanent === '1';
        form?.querySelectorAll('[data-clear-select="id_profesor"]').forEach((button) => {
            const canRemove = hasCurrentClasses || hasPermanentLink;
            button.hidden = !showingAssigned || !hasAssignedProfessors;
            button.disabled = !showingAssigned || !hasAssignedProfessors || !canRemove;
        });
    });

    document.addEventListener('change', (event) => {
        const gradeSelect = event.target.closest('[data-grade-block]');
        if (!gradeSelect) return;
        const blockInput = gradeSelect.form?.querySelector('[name="id_bloque"]');
        if (blockInput) {
            blockInput.value = gradeSelect.selectedOptions[0]?.dataset.blockId || '';
        }
    });

    document.addEventListener('change', (event) => {
        const modeOption = event.target.closest('[data-center-break-mode]');
        if (!modeOption) return;
        if (!modeOption.checked) return;
        const mode = modeOption.value;
        const form = modeOption.closest('form');
        const sharedBreak = form?.querySelector('[data-center-shared-break]');
        const splitBreaks = form?.querySelector('[data-center-split-breaks]');
        const sharedInput = form?.querySelector('[name="descanso_compartido"]');
        const splitInputs = form?.querySelectorAll('[name="descanso_calendario_1"], [name="descanso_calendario_2"]');
        if (sharedBreak) sharedBreak.hidden = mode !== 'compartido';
        if (splitBreaks) splitBreaks.hidden = mode !== 'separado';
        if (sharedInput) sharedInput.required = mode === 'compartido';
        splitInputs?.forEach((input) => { input.required = mode === 'separado'; });
    });

    document.addEventListener('change', (event) => {
        const form = event.target.closest('form[data-room-availability]');
        if (form) updateRoomAvailability(form);
    });

    const teacherSearch = document.getElementById('profesor-search');
    const teacherId = document.getElementById('profesor-id');
    const teacherOptions = document.getElementById('profesor-options');
    const teacherSubmit = document.getElementById('profesor-submit');
    const teacherPicker = document.getElementById('teacher-picker');
    const teacherToggle = teacherPicker?.querySelector('.teacher-picker-toggle');
    const teacherNoResults = teacherPicker?.querySelector('.teacher-no-results');
    const professorEmptyState = document.getElementById('profesor-empty-title')?.closest('.empty-state-panel');

    if (teacherSearch && teacherId && teacherOptions && teacherSubmit && teacherPicker) {
        const options = Array.from(teacherOptions.querySelectorAll('.teacher-option'));
        let activeIndex = -1;

        const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLocaleLowerCase();
        const closeOptions = () => {
            teacherOptions.hidden = true;
            teacherSearch.setAttribute('aria-expanded', 'false');
            teacherToggle?.setAttribute('aria-expanded', 'false');
            if (professorEmptyState) professorEmptyState.hidden = Boolean(teacherId.value);
            activeIndex = -1;
            options.forEach((option) => option.setAttribute('aria-selected', 'false'));
        };
        const filterOptions = (open = true) => {
            const query = normalize(teacherSearch.value);
            let exactMatch = null;
            let visibleCount = 0;
            options.forEach((option) => {
                const matches = normalize(option.dataset.name).includes(query);
                option.hidden = !matches;
                option.setAttribute('aria-selected', 'false');
                if (matches) visibleCount++;
                if (normalize(option.dataset.name) === query) exactMatch = option;
            });
            if (teacherNoResults) teacherNoResults.hidden = visibleCount > 0;
            teacherId.value = exactMatch?.dataset.id || '';
            teacherSubmit.disabled = !exactMatch;
            if (open) {
                teacherOptions.hidden = false;
                teacherSearch.setAttribute('aria-expanded', 'true');
                teacherToggle?.setAttribute('aria-expanded', 'true');
                if (professorEmptyState) professorEmptyState.hidden = true;
            }
            return exactMatch;
        };
        const chooseOption = (option) => {
            teacherSearch.value = option.dataset.name;
            teacherId.value = option.dataset.id;
            teacherSubmit.disabled = false;
            closeOptions();
            teacherSearch.form?.requestSubmit();
        };

        teacherSearch.addEventListener('input', () => filterOptions());
        teacherSearch.addEventListener('focus', () => filterOptions());
        teacherSearch.addEventListener('change', () => {
            if (filterOptions(false)) {
                teacherSearch.form?.requestSubmit();
            }
        });
        teacherToggle?.addEventListener('click', () => {
            if (teacherOptions.hidden) {
                filterOptions();
                teacherSearch.focus();
            } else {
                closeOptions();
            }
        });
        teacherOptions.addEventListener('click', (event) => {
            const option = event.target.closest('.teacher-option');
            if (option) chooseOption(option);
        });
        teacherSearch.addEventListener('keydown', (event) => {
            const visibleOptions = options.filter((option) => !option.hidden);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (teacherOptions.hidden) filterOptions();
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                activeIndex = (activeIndex + direction + visibleOptions.length) % visibleOptions.length;
                options.forEach((option) => option.setAttribute('aria-selected', 'false'));
                visibleOptions[activeIndex]?.setAttribute('aria-selected', 'true');
                visibleOptions[activeIndex]?.scrollIntoView({block: 'nearest'});
            } else if (event.key === 'Enter' && !teacherOptions.hidden && activeIndex >= 0) {
                event.preventDefault();
                chooseOption(visibleOptions[activeIndex]);
            } else if (event.key === 'Escape') {
                closeOptions();
            }
        });
        document.addEventListener('click', (event) => {
            if (!teacherPicker.contains(event.target)) closeOptions();
        });
        teacherSearch.form?.addEventListener('submit', (event) => {
            if (!filterOptions(false)) {
                event.preventDefault();
                teacherSearch.focus();
            }
        });
        filterOptions(false);
    }
});
