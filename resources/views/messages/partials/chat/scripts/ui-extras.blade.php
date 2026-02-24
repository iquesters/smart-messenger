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

    // Jump to bottom button functionality (Gmail style)
    const messagesContainer = document.getElementById('messagesContainer');
    const jumpToBottomBtn = document.getElementById('jumpToBottomBtn');

    if (messagesContainer && jumpToBottomBtn) {
        let hideTimeout;
        let isUserScrolling = false;

        // Function to show the button
        function showJumpButton() {
            const isNearBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 100;
            
            if (!isNearBottom) {
                jumpToBottomBtn.classList.remove('d-none');
                
                // Clear existing timeout
                if (hideTimeout) {
                    clearTimeout(hideTimeout);
                }
                
                // Auto-hide after 3 seconds of no activity
                hideTimeout = setTimeout(() => {
                    if (!isUserScrolling) {
                        jumpToBottomBtn.classList.add('d-none');
                    }
                }, 3000);
            }
        }

        // Function to hide the button
        function hideJumpButton() {
            jumpToBottomBtn.classList.add('d-none');
            if (hideTimeout) {
                clearTimeout(hideTimeout);
            }
        }

        // Show/hide button based on scroll position
        messagesContainer.addEventListener('scroll', function() {
            isUserScrolling = true;
            const isNearBottom = this.scrollHeight - this.scrollTop - this.clientHeight < 100;
            
            if (isNearBottom) {
                hideJumpButton();
            } else {
                showJumpButton();
            }
            
            // Reset scrolling flag after a short delay
            setTimeout(() => {
                isUserScrolling = false;
            }, 150);
        });

        // Show button on mouse move in messages area
        messagesContainer.addEventListener('mousemove', function() {
            const isNearBottom = this.scrollHeight - this.scrollTop - this.clientHeight < 100;
            if (!isNearBottom) {
                showJumpButton();
            }
        });

        // Show button when mouse enters messages area
        messagesContainer.addEventListener('mouseenter', function() {
            const isNearBottom = this.scrollHeight - this.scrollTop - this.clientHeight < 100;
            if (!isNearBottom) {
                showJumpButton();
            }
        });

        // Keep button visible when hovering over it
        jumpToBottomBtn.addEventListener('mouseenter', function() {
            if (hideTimeout) {
                clearTimeout(hideTimeout);
            }
        });

        // Resume auto-hide when mouse leaves the button
        jumpToBottomBtn.addEventListener('mouseleave', function() {
            hideTimeout = setTimeout(() => {
                if (!isUserScrolling) {
                    jumpToBottomBtn.classList.add('d-none');
                }
            }, 3000);
        });

        // Scroll to bottom when button is clicked
        jumpToBottomBtn.querySelector('button').addEventListener('click', function() {
            messagesContainer.scrollTo({
                top: messagesContainer.scrollHeight,
                behavior: 'smooth'
            });
            hideJumpButton();
        });

        // Auto-scroll to bottom on page load
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }


    // Star rating js
    document.querySelectorAll('.star-rating').forEach(ratingBlock => {
        const stars = ratingBlock.querySelectorAll('.star');

        stars.forEach(star => {
            star.addEventListener('click', function () {
                const rating = this.dataset.value;
                const messageId = ratingBlock.dataset.messageId;

                // Fill stars up to clicked one
                stars.forEach(s => {
                    if (s.dataset.value <= rating) {
                        s.classList.remove('fa-regular');
                        s.classList.add('fa-solid');
                    } else {
                        s.classList.remove('fa-solid');
                        s.classList.add('fa-regular');
                    }
                });

                // Set modal values
                document.getElementById('ratingValue').value = rating;
                document.getElementById('messageId').value = messageId;

                // Show modal
                const modal = new bootstrap.Modal(
                    document.getElementById('feedbackModal')
                );
                modal.show();
            });
        });
    });
</script>
