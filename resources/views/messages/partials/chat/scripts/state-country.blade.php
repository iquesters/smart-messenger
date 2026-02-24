<script>
    // Country codes data - fetched dynamically
    let countriesData = [];
    let allContactsData = [];
    
    // Elements
    const mainHeader = document.getElementById('mainHeader');
    const backHeader = document.getElementById('backHeader');
    const backHeaderTitle = document.getElementById('backHeaderTitle');
    const mainSearchBox = document.getElementById('mainSearchBox');
    const newChatBtn = document.getElementById('newChatBtn');
    const backBtn = document.getElementById('backBtn');
    
    const contactsView = document.getElementById('contactsView');
    const chatOptions = document.getElementById('chatOptions');
    const newContactForm = document.getElementById('newContactForm');
    const newGroupForm = document.getElementById('newGroupForm');
    
    const newContactBtn = document.getElementById('newContactBtn');
    const newGroupBtn = document.getElementById('newGroupBtn');
    const chatSearchInput = document.getElementById('chatSearch');

    const chatHeader = document.getElementById('chatHeader');
    const detailsPanel = document.getElementById('detailsPanel');
    const closeDetails = document.getElementById('closeDetails');

    // Country code elements
    const countryCodeBtn = document.getElementById('countryCodeBtn');
    const countryDropdown = document.getElementById('countryDropdown');
    const countrySearch = document.getElementById('countrySearch');
    const countryList = document.getElementById('countryList');
    const selectedFlag = document.getElementById('selectedFlag');
    const selectedCode = document.getElementById('selectedCode');

    // Navigation state
    let currentView = 'contacts';

    // Load countries on page load
    loadCountries();

    async function loadCountries() {
        try {
            const response = await fetch('https://restcountries.com/v3.1/all');
            const countries = await response.json();
            
            // Process and sort countries
            countriesData = countries
                .filter(country => country.idd && country.idd.root)
                .map(country => ({
                    name: country.name.common,
                    flag: country.flag,
                    code: country.idd.root + (country.idd.suffixes ? country.idd.suffixes[0] : ''),
                    callingCode: country.idd.root + (country.idd.suffixes ? country.idd.suffixes[0] : '')
                }))
                .sort((a, b) => a.name.localeCompare(b.name));
            
            renderCountries(countriesData);
        } catch (error) {
            console.error('Error loading countries:', error);
            // Fallback to basic list if API fails
            countriesData = [
                { name: 'United States', flag: '🇺🇸', code: '+1' },
                { name: 'United Kingdom', flag: '🇬🇧', code: '+44' },
                { name: 'India', flag: '🇮🇳', code: '+91' },
                { name: 'Canada', flag: '🇨🇦', code: '+1' }
            ];
            renderCountries(countriesData);
        }
    }

    function renderCountries(countries) {
        countryList.innerHTML = countries.map(country => `
            <div class="country-item d-flex align-items-center p-2 hover-bg-light" 
                 style="cursor: pointer;"
                 data-flag="${country.flag}" 
                 data-code="${country.code}">
                <span style="font-size: 1.2rem; margin-right: 8px;">${country.flag}</span>
                <span class="flex-grow-1">${country.name}</span>
                <span class="text-muted">${country.code}</span>
            </div>
        `).join('');

        // Add click handlers to country items
        document.querySelectorAll('.country-item').forEach(item => {
            item.addEventListener('click', function() {
                const flag = this.dataset.flag;
                const code = this.dataset.code;
                selectCountryCode(flag, code);
            });
        });
    }

    function selectCountryCode(flag, code) {
        selectedFlag.textContent = flag;
        selectedCode.textContent = code;
        countryDropdown.classList.add('d-none');
    }

    // Toggle country dropdown
    if (countryCodeBtn) {
        countryCodeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            countryDropdown.classList.toggle('d-none');
        });
    }

    // Search countries
    if (countrySearch) {
        countrySearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const filteredCountries = countriesData.filter(country => 
                country.name.toLowerCase().includes(searchTerm) || 
                country.code.includes(searchTerm)
            );
            renderCountries(filteredCountries);
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!countryDropdown.contains(e.target) && e.target !== countryCodeBtn) {
            countryDropdown.classList.add('d-none');
        }
    });

    if (chatHeader) {
        chatHeader.addEventListener('click', () => {
            detailsPanel.classList.remove('d-none');
        });
    }

    if (closeDetails) {
        closeDetails.addEventListener('click', () => {
            detailsPanel.classList.add('d-none');
        });
    }

</script>
