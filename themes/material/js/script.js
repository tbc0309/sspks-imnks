(function () {
    'use strict';

    var translations = window.SSPKS_I18N || {};
    function t(key, fallback, values) {
        var text = typeof translations[key] === 'string' ? translations[key] : fallback;
        Object.keys(values || {}).forEach(function (name) {
            text = text.split('{' + name + '}').join(String(values[name]));
        });
        return text;
    }

    function afterPageLoad(callback) {
        if (document.readyState === 'complete') {
            window.setTimeout(callback, 0);
            return;
        }
        window.addEventListener('load', callback, { once: true });
    }

    function onMediaChange(query, callback) {
        if (typeof query.addEventListener === 'function') {
            query.addEventListener('change', callback);
        } else if (typeof query.addListener === 'function') {
            query.addListener(callback);
        }
    }

    /* Show accessibility focus indicators only during keyboard Tab navigation. */
    function setupInputModality() {
        var root = document.documentElement;
        var keyboardClass = 'keyboardNavigation';

        window.addEventListener('keydown', function (event) {
            if (event.key === 'Tab') {
                root.classList.add(keyboardClass);
            }
        }, true);
        window.addEventListener('pointerdown', function () {
            root.classList.remove(keyboardClass);
        }, true);
    }

    function setupLanguageMenu() {
        var root = document.querySelector('[data-language-menu]');
        if (!root) {
            return;
        }
        var button = root.querySelector('[data-language-button]');
        var menu = root.querySelector('[data-language-list]');
        if (!button || !menu) {
            return;
        }

        function setOpen(open, focusCurrent) {
            menu.hidden = !open;
            button.setAttribute('aria-expanded', String(open));
            if (open && focusCurrent) {
                var current = menu.querySelector('[aria-current="true"]') || menu.querySelector('a');
                if (current) {
                    current.focus();
                }
            }
        }

        button.addEventListener('click', function () {
            setOpen(menu.hidden, false);
        });
        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setOpen(true, true);
            }
        });
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                setOpen(false, false);
            }
        });
        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false, false);
                button.focus();
            }
        });
    }

    /* Package details and notice dialogs */
    function setupDetails() {
        document.addEventListener('click', function (event) {
            var target = event.target instanceof Element ? event.target : null;
            var button = target && target.closest('[data-toggle-details]');
            if (!button) {
                return;
            }

            var card = button.closest('.spk-card');
            var details = card && card.querySelector('.spk-details');
            if (!details) {
                return;
            }

            var expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', String(!expanded));
            button.textContent = expanded ? t('details', 'View details') : t('hide_details', 'Hide details');
            card.classList.toggle('is-details-expanded', !expanded);
            details.setAttribute('aria-hidden', String(expanded));
            if (expanded) {
                details.setAttribute('inert', '');
            } else {
                details.removeAttribute('inert');
            }
            details.classList.toggle('spk-details-hidden', expanded);
        });
    }

    function setupNoticeDialog(buttonSelector, dialogId) {
        var dialog = document.getElementById(dialogId);
        var button = document.querySelector(buttonSelector);
        if (!dialog || !button) {
            return;
        }
        button.addEventListener('click', function () {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        });
        dialog.addEventListener('click', function (event) {
            var target = event.target instanceof HTMLButtonElement ? event.target : null;
            if (target && target.value === 'confirm' && typeof dialog.close !== 'function') {
                dialog.removeAttribute('open');
            }
        });
    }

    function setupNotices() {
        setupNoticeDialog('[data-open-dsm6-notice]', 'dsm6-notice');
        setupNoticeDialog('[data-open-safety-notice]', 'safety-notice');
    }

    /* Address copy and back-to-top */
    function copyText(text) {
        if (!navigator.clipboard || !window.isSecureContext) {
            return Promise.reject(new Error('Clipboard API is unavailable.'));
        }
        return navigator.clipboard.writeText(text);
    }

    function setupCopyButton() {
        var button = document.querySelector('[data-copy-source]');
        var source = document.getElementById('source-url');
        var status = document.querySelector('[data-copy-status]');
        if (!button || !source) {
            return;
        }

        button.addEventListener('click', function () {
            copyText(source.textContent.trim())
                .then(function () {
                    button.textContent = t('copied', 'Copied');
                    if (status) {
                        status.textContent = t('copy_success_announcement', 'Package source URL copied to the clipboard.');
                    }
                    window.setTimeout(function () {
                        button.textContent = t('copy_address', 'Copy address');
                    }, 1600);
                })
                .catch(function () {
                    button.textContent = t('copy_manual', 'Copy manually');
                    if (status) {
                        status.textContent = t('copy_failure_announcement', 'Automatic copying failed. Copy the package source URL manually.');
                    }
                });
        });
    }

    function setupBackToTop() {
        var button = document.querySelector('[data-back-to-top]');
        if (!button) {
            return;
        }

        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        var updateFrame = 0;

        function updateVisibility() {
            updateFrame = 0;
            var visible = window.scrollY > 480;
            button.classList.toggle('is-visible', visible);
            button.setAttribute('aria-hidden', String(!visible));
            button.tabIndex = visible ? 0 : -1;
        }

        button.hidden = false;
        button.addEventListener('click', function () {
            window.scrollTo({
                top: 0,
                behavior: reducedMotion.matches ? 'auto' : 'smooth',
            });
        });
        window.addEventListener(
            'scroll',
            function () {
                if (updateFrame) {
                    return;
                }
                updateFrame = window.requestAnimationFrame(updateVisibility);
            },
            { passive: true },
        );
        updateVisibility();
    }

    /* Advertisement carousel */
    function setupAdvertisementCarousel() {
        var carousels = Array.from(document.querySelectorAll('[data-ad-carousel]'));

        carousels.forEach(function (carousel) {
            var slides = Array.from(carousel.querySelectorAll('[data-ad-slide]'));
            var dots = Array.from(carousel.querySelectorAll('[data-ad-index]'));

            carousel.hidden = true;
            slides.forEach(function (slide) {
                slide.hidden = true;
                slide.classList.remove('is-active');
                slide.setAttribute('aria-hidden', 'true');
            });
            dots.forEach(function (dot) {
                dot.setAttribute('aria-current', 'false');
            });

            if (!slides.length) {
                afterPageLoad(function () {
                    carousel.hidden = false;
                });
                return;
            }

            var interval = Math.max(3000, Math.min(60000, Number(carousel.getAttribute('data-ad-interval')) || 6000));
            var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
            var currentIndex = 0;
            var timer = null;
            var activationId = 0;
            var slideLoads = [];

            function normaliseIndex(index) {
                return (index + slides.length) % slides.length;
            }

            function decodeImage(image) {
                if (typeof image.decode !== 'function') {
                    return Promise.resolve();
                }
                return image.decode().catch(function () {
                });
            }

            function loadSlide(index) {
                var normalisedIndex = normaliseIndex(index);
                var slide = slides[normalisedIndex];
                var image = slide && slide.querySelector('img');
                var source = image && image.getAttribute('data-src');

                if (!image || !source) {
                    return Promise.reject(new Error('Advertisement image is missing.'));
                }
                if (image.getAttribute('data-loaded') === 'true') {
                    return Promise.resolve(normalisedIndex);
                }
                if (slideLoads[normalisedIndex]) {
                    return slideLoads[normalisedIndex];
                }

                slideLoads[normalisedIndex] = new Promise(function (resolve, reject) {
                    function cleanup() {
                        image.removeEventListener('load', handleLoad);
                        image.removeEventListener('error', handleError);
                    }

                    function handleLoad() {
                        cleanup();
                        image.setAttribute('data-loaded', 'true');
                        decodeImage(image).then(function () {
                            resolve(normalisedIndex);
                        });
                    }

                    function handleError() {
                        cleanup();
                        image.removeAttribute('src');
                        reject(new Error('Advertisement image failed to load.'));
                    }

                    image.addEventListener('load', handleLoad);
                    image.addEventListener('error', handleError);
                    image.src = source;
                }).catch(function (error) {
                    slideLoads[normalisedIndex] = null;
                    throw error;
                });

                return slideLoads[normalisedIndex];
            }

            function showLoadedSlide(index) {
                currentIndex = normaliseIndex(index);
                slides.forEach(function (slide, slideIndex) {
                    var active = slideIndex === currentIndex;
                    slide.hidden = !active;
                    slide.classList.toggle('is-active', active);
                    slide.setAttribute('aria-hidden', String(!active));
                });
                dots.forEach(function (dot, dotIndex) {
                    dot.setAttribute('aria-current', String(dotIndex === currentIndex));
                });
            }

            function stopRotation() {
                if (timer !== null) {
                    window.clearTimeout(timer);
                    timer = null;
                }
            }

            function startRotation() {
                stopRotation();
                if (
                    slides.length < 2 ||
                    carousel.hidden ||
                    document.hidden ||
                    reducedMotion.matches ||
                    carousel.matches(':hover') ||
                    carousel.contains(document.activeElement)
                ) {
                    return;
                }
                timer = window.setTimeout(function () {
                    requestSlide(currentIndex + 1);
                }, interval);
            }

            function requestSlide(index) {
                var requestId = ++activationId;
                stopRotation();
                loadSlide(index)
                    .then(function (loadedIndex) {
                        if (requestId !== activationId) {
                            return;
                        }
                        showLoadedSlide(loadedIndex);
                        startRotation();
                    })
                    .catch(function () {
                        if (requestId === activationId) {
                            startRotation();
                        }
                    });
            }

            dots.forEach(function (dot) {
                dot.addEventListener('click', function () {
                    requestSlide(Number(dot.getAttribute('data-ad-index')) || 0);
                });
            });
            carousel.addEventListener('mouseenter', stopRotation);
            carousel.addEventListener('mouseleave', startRotation);
            carousel.addEventListener('focusin', stopRotation);
            carousel.addEventListener('focusout', function () {
                window.requestAnimationFrame(startRotation);
            });
            document.addEventListener('visibilitychange', startRotation);
            onMediaChange(reducedMotion, startRotation);

            afterPageLoad(function () {
                loadSlide(0)
                    .then(function () {
                        showLoadedSlide(0);
                        carousel.hidden = false;
                        startRotation();
                    })
                    .catch(function () {
                        // Hide the advertisement area if the first image fails.
                    });
            });
        });
    }

    /* Model filtering */
    function setupModelSearch() {
        var browser = document.querySelector('[data-model-browser]');
        var input = browser && browser.querySelector('[data-model-search]');
        if (!browser || !input) {
            return;
        }

        var grid = document.querySelector('[data-model-grid]');
        if (!grid) {
            return;
        }
        var naturalCards = Array.from(grid.querySelectorAll('[data-model-card]'));
        if (!naturalCards.length) {
            return;
        }
        var naturalPositions = new Map();
        naturalCards.forEach(function (card, index) {
            naturalPositions.set(card, index);
        });
        var priorityCards = naturalCards.slice().sort(function (left, right) {
            var leftRank = Number(left.getAttribute('data-priority-rank')) || Number.MAX_SAFE_INTEGER;
            var rightRank = Number(right.getAttribute('data-priority-rank')) || Number.MAX_SAFE_INTEGER;
            return leftRank === rightRank ? naturalPositions.get(left) - naturalPositions.get(right) : leftRank - rightRank;
        });
        var count = browser.querySelector('[data-model-count]');
        var toggle = browser.querySelector('[data-model-toggle]');
        var mobileQuery = window.matchMedia('(max-width: 599px)');
        var expanded = browser.getAttribute('data-show-all') === 'true';
        var renderedOrder = null;

        function visibleLimit() {
            var attribute = mobileQuery.matches ? 'data-limit-mobile' : 'data-limit-desktop';
            return Math.max(1, Number(browser.getAttribute(attribute)) || (mobileQuery.matches ? 12 : 18));
        }

        function applyOrder(cards) {
            if (renderedOrder === cards) {
                return;
            }
            var fragment = document.createDocumentFragment();
            cards.forEach(function (card) {
                fragment.appendChild(card);
            });
            grid.appendChild(fragment);
            renderedOrder = cards;
        }

        function filterModels() {
            var cards = expanded ? naturalCards : priorityCards;
            applyOrder(cards);
            var keyword = input.value.trim().toLowerCase();
            var matchedCards = cards.filter(function (card) {
                var name = (card.getAttribute('data-model-name') || '').toLowerCase();
                return keyword === '' || name.indexOf(keyword) !== -1;
            });
            var matches = new Set(matchedCards);
            var limit = keyword === '' && !expanded ? visibleLimit() : matchedCards.length;
            var visible = Math.min(limit, matchedCards.length);
            var matchedPosition = 0;
            cards.forEach(function (card) {
                if (!matches.has(card)) {
                    card.hidden = true;
                    return;
                }
                card.hidden = matchedPosition >= limit;
                matchedPosition++;
            });
            if (count) {
                count.textContent = t('shown_count', 'Showing {visible} / {total}', { visible: visible, total: matchedCards.length });
            }
            if (toggle) {
                toggle.hidden = keyword !== '' || matchedCards.length <= visibleLimit();
                toggle.textContent = expanded ? t('show_less', 'Show fewer models') : t('show_all', 'Show all models');
                toggle.setAttribute('aria-expanded', String(expanded));
            }
        }

        var filterFrame = 0;
        input.addEventListener('input', function () {
            if (filterFrame) {
                window.cancelAnimationFrame(filterFrame);
            }
            filterFrame = window.requestAnimationFrame(function () {
                filterFrame = 0;
                filterModels();
            });
        });
        if (toggle) {
            toggle.addEventListener('click', function () {
                expanded = !expanded;
                filterModels();
                if (!expanded) {
                    browser.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }
        onMediaChange(mobileQuery, filterModels);
        filterModels();
    }

    /* Palette switcher */
    function setupPaletteSwitcher() {
        var allowed = ['teal', 'ocean', 'violet', 'dark'];
        var themeColors = { teal: '#0b3b46', ocean: '#123d63', violet: '#432663', dark: '#09090b' };
        var buttons = Array.from(document.querySelectorAll('[data-palette]'));
        if (!buttons.length) {
            return;
        }

        function activate(palette) {
            if (allowed.indexOf(palette) < 0) {
                palette = document.documentElement.getAttribute('data-default-palette') || 'teal';
            }
            document.documentElement.dataset.palette = palette;
            var themeColor = document.querySelector('meta[name="theme-color"]');
            if (themeColor) {
                themeColor.setAttribute('content', themeColors[palette]);
            }
            buttons.forEach(function (button) {
                button.setAttribute('aria-pressed', String(button.getAttribute('data-palette') === palette));
            });
            try {
                localStorage.setItem('sspks-palette', palette);
            } catch (error) {}
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                activate(button.getAttribute('data-palette'));
            });
        });
        activate(document.documentElement.dataset.palette);
    }

    /* Progressive package display */
    function setupProgressivePackages() {
        var grid = document.querySelector('[data-progressive-grid]');
        var loader = document.querySelector('[data-package-loader]');
        if (!grid || !loader) {
            return;
        }

        var cards = Array.from(grid.querySelectorAll('[data-progressive-card]'));
        if (!cards.length) {
            return;
        }

        var progress = loader.querySelector('[data-package-progress]');
        var loadButton = loader.querySelector('[data-load-more-packages]');
        var batchSize = window.matchMedia('(max-width: 599px)').matches ? 6 : 12;
        var visibleCount = 0;
        var revealing = false;
        var observer = null;

        function updateLoader() {
            var complete = visibleCount >= cards.length;
            loader.hidden = complete;
            if (progress) {
                progress.textContent = complete
                    ? t('packages_all', 'All {total} packages are shown', { total: cards.length })
                    : t('packages_progress', 'Showing {visible} / {total}', { visible: visibleCount, total: cards.length });
            }
            if (complete && observer) {
                observer.disconnect();
            }
        }

        function revealNextBatch() {
            if (revealing || visibleCount >= cards.length) {
                return;
            }
            revealing = true;
            var nextCount = Math.min(visibleCount + batchSize, cards.length);
            for (var index = visibleCount; index < nextCount; index++) {
                cards[index].hidden = false;
                cards[index].classList.add('is-revealed');
            }
            visibleCount = nextCount;
            updateLoader();
            window.requestAnimationFrame(function () {
                revealing = false;
            });
        }

        if (loadButton) {
            loadButton.addEventListener('click', revealNextBatch);
        }

        revealNextBatch();
        if ('IntersectionObserver' in window && !loader.hidden) {
            observer = new IntersectionObserver(
                function (entries) {
                    if (
                        entries.some(function (entry) {
                            return entry.isIntersecting;
                        })
                    ) {
                        revealNextBatch();
                    }
                },
                { rootMargin: '500px 0px' },
            );
            observer.observe(loader);
        }
    }

    setupInputModality();

    /* Page initialization */
    document.addEventListener('DOMContentLoaded', function () {
        setupLanguageMenu();
        setupDetails();
        setupNotices();
        setupCopyButton();
        setupBackToTop();
        setupAdvertisementCarousel();
        setupPaletteSwitcher();
        setupModelSearch();
        setupProgressivePackages();
    });
})();
