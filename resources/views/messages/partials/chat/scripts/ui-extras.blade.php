<script>
    const devModeStorageKey = 'smartMessengerDevModeEnabled';
    const devModeToggle = document.getElementById('devModeToggle');
    const devModeSections = document.querySelectorAll('.dev-mode-section');

    function applyDevModeState(isEnabled) {
        devModeSections.forEach(section => {
            section.classList.toggle('d-none', !isEnabled);
        });

        if (devModeToggle) {
            devModeToggle.checked = isEnabled;
        }
    }

    if (devModeToggle && devModeToggle.dataset.isSuperAdmin === '1') {
        let isDevModeEnabled = false;

        try {
            isDevModeEnabled = localStorage.getItem(devModeStorageKey) === '1';
        } catch (error) {
            console.warn('Unable to read dev mode state from localStorage', error);
        }

        applyDevModeState(isDevModeEnabled);

        devModeToggle.addEventListener('change', function () {
            isDevModeEnabled = this.checked;

            try {
                localStorage.setItem(devModeStorageKey, isDevModeEnabled ? '1' : '0');
            } catch (error) {
                console.warn('Unable to persist dev mode state to localStorage', error);
            }

            applyDevModeState(isDevModeEnabled);
        });
    }

    function bindStarRatings(root = document) {
        root.querySelectorAll('.star-rating').forEach(ratingBlock => {
            if (ratingBlock.dataset.bound === '1') {
                return;
            }

            const stars = ratingBlock.querySelectorAll('.star');
            stars.forEach(star => {
                star.addEventListener('click', function () {
                    const rating = this.dataset.value;
                    const messageId = ratingBlock.dataset.messageId;

                    stars.forEach(s => {
                        if (s.dataset.value <= rating) {
                            s.classList.remove('fa-regular');
                            s.classList.add('fa-solid');
                        } else {
                            s.classList.remove('fa-solid');
                            s.classList.add('fa-regular');
                        }
                    });

                    document.getElementById('ratingValue').value = rating;
                    document.getElementById('messageId').value = messageId;

                    const modal = new bootstrap.Modal(document.getElementById('feedbackModal'));
                    modal.show();
                });
            });

            ratingBlock.dataset.bound = '1';
        });
    }

    // Jump to bottom + incremental history loading
    const messagesContainer = document.getElementById('messagesContainer');
    const jumpToBottomBtn = document.getElementById('jumpToBottomBtn');
    const historyUrl = @json(route('messages.history'));

    if (messagesContainer && jumpToBottomBtn) {
        let hideTimeout;
        let isUserScrolling = false;
        let isLoadingOlder = false;

        function dedupeDateSeparators() {
            messagesContainer.querySelectorAll('.chat-date-separator').forEach(separator => {
                const date = separator.dataset.date;
                const prev = separator.previousElementSibling;
                if (prev && prev.classList.contains('chat-message-item') && prev.dataset.messageDate === date) {
                    separator.remove();
                }
            });
        }

        async function loadOlderMessages() {
            if (isLoadingOlder) return;
            if (messagesContainer.dataset.hasMore !== '1') return;

            const profileId = messagesContainer.dataset.profileId;
            const selectedContactValue = messagesContainer.dataset.selectedContact;
            const beforeId = messagesContainer.dataset.oldestId;
            if (!profileId || !selectedContactValue || !beforeId) return;

            isLoadingOlder = true;
            const previousHeight = messagesContainer.scrollHeight;
            const previousTop = messagesContainer.scrollTop;

            try {
                const params = new URLSearchParams({
                    profile_id: profileId,
                    contact: selectedContactValue,
                    before_id: beforeId,
                    limit: '10',
                });

                const response = await fetch(`${historyUrl}?${params.toString()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();
                if (!data.success || !data.html) {
                    messagesContainer.dataset.hasMore = '0';
                    return;
                }

                messagesContainer.insertAdjacentHTML('afterbegin', data.html);
                dedupeDateSeparators();

                bindStarRatings(messagesContainer);
                if (typeof window.initializeDevModeDiagnostics === 'function') {
                    window.initializeDevModeDiagnostics();
                }

                const devModeToggleInput = document.getElementById('devModeToggle');
                if (devModeToggleInput && devModeToggleInput.checked) {
                    messagesContainer.querySelectorAll('.dev-mode-section').forEach(section => {
                        section.classList.remove('d-none');
                    });
                }

                messagesContainer.dataset.oldestId = data.oldest_id ?? messagesContainer.dataset.oldestId;
                messagesContainer.dataset.hasMore = data.has_more ? '1' : '0';

                const heightDiff = messagesContainer.scrollHeight - previousHeight;
                messagesContainer.scrollTop = previousTop + heightDiff;
            } catch (error) {
                console.error('Failed to load older messages', error);
            } finally {
                isLoadingOlder = false;
            }
        }

        function showJumpButton() {
            const isNearBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 100;

            if (!isNearBottom) {
                jumpToBottomBtn.classList.remove('d-none');
                if (hideTimeout) {
                    clearTimeout(hideTimeout);
                }

                hideTimeout = setTimeout(() => {
                    if (!isUserScrolling) {
                        jumpToBottomBtn.classList.add('d-none');
                    }
                }, 3000);
            }
        }

        function hideJumpButton() {
            jumpToBottomBtn.classList.add('d-none');
            if (hideTimeout) {
                clearTimeout(hideTimeout);
            }
        }

        messagesContainer.addEventListener('scroll', function() {
            isUserScrolling = true;
            const isNearBottom = this.scrollHeight - this.scrollTop - this.clientHeight < 100;

            if (this.scrollTop <= 40) {
                loadOlderMessages();
            }

            if (isNearBottom) {
                hideJumpButton();
            } else {
                showJumpButton();
            }

            setTimeout(() => {
                isUserScrolling = false;
            }, 150);
        });

        messagesContainer.addEventListener('mousemove', function() {
            const isNearBottom = this.scrollHeight - this.scrollTop - this.clientHeight < 100;
            if (!isNearBottom) {
                showJumpButton();
            }
        });

        messagesContainer.addEventListener('mouseenter', function() {
            const isNearBottom = this.scrollHeight - this.scrollTop - this.clientHeight < 100;
            if (!isNearBottom) {
                showJumpButton();
            }
        });

        jumpToBottomBtn.addEventListener('mouseenter', function() {
            if (hideTimeout) {
                clearTimeout(hideTimeout);
            }
        });

        jumpToBottomBtn.addEventListener('mouseleave', function() {
            hideTimeout = setTimeout(() => {
                if (!isUserScrolling) {
                    jumpToBottomBtn.classList.add('d-none');
                }
            }, 3000);
        });

        jumpToBottomBtn.querySelector('button').addEventListener('click', function() {
            messagesContainer.scrollTo({
                top: messagesContainer.scrollHeight,
                behavior: 'smooth'
            });
            hideJumpButton();
        });

        bindStarRatings(messagesContainer);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    } else {
        bindStarRatings(document);
    }
</script>
