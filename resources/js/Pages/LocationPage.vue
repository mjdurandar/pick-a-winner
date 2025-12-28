<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';

// ✅ Define Props to Receive Locations from Backend
const props = defineProps({
    event: Object,
    locations: Array,
    flash: Object // Add this line
});

// ✅ Reactive Data
const searchQuery = ref(''); // ✅ Search query input
const copiedIndex = ref(null); // ✅ Index of copied location link
const showPasswordModal = ref(false);
const showEventPasswordModal = ref(false);
const showLocationModal = ref(false);
const showAllPasswordsModal = ref(false);
const isEditing = ref(false);
const selectedLocation = ref(null);
const selectedLocationsForExport = ref([]);
const newPassword = ref('');
const confirmPassword = ref('');
const eventNewPassword = ref('');
const eventConfirmPassword = ref('');
const allLocationsNewPassword = ref('');
const allLocationsConfirmPassword = ref('');
const showMailchimpModal = ref(false);
const mailchimpLists = ref([]);
const selectedList = ref('');
const isImporting = ref(false);
const tags = ref(''); // Add new ref for tags
const showMailchimpSettingsModal = ref(false);
const mailchimpSettings = ref({
    auto_sync: false,
    default_list_id: '',
    default_tags: '',
    enabled_locations: [],
    film_tour: '',  // Default to WM
    mailchimp_account: 'anz', // Default to ANZ
    event_id: props.event.id  // Add event_id
});
const availableLists = ref([]);
const availableAccounts = ref([]);
const isSettingsLoading = ref(false);
const showEventbriteModal = ref(false);
const eventbriteLink = ref('');
const isFetchingEventbrite = ref(false);
const eventbriteAttendees = ref([]);
const eventbriteEventId = ref('');
const isImportingEventbrite = ref(false);
const showEventbriteMailchimpModal = ref(false);
const eventbriteMailchimpAccount = ref('');
const eventbriteMailchimpLists = ref([]);
const eventbriteSelectedList = ref('');
const eventbriteAvailableAccounts = ref([]);
const isLoadingEventbriteLists = ref(false);
const eventbriteTags = ref('');
const eventbriteDefaultTags = ref([]);
const selectedCountry = ref(null);
const selectedCategory = ref('');
const showTicketReportModal = ref(false);
const ticketReportData = ref(null);
const isLoadingReport = ref(false);
const isGeneratingTicketCopyData = ref(false);
const showMailchimpReportModal = ref(false);
const mailchimpReportData = ref(null);
const isLoadingMailchimpReport = ref(false);
const isGeneratingMailchimpCopyData = ref(false);

// Add computed property for tag preview
const tagPreview = computed(() => {
    if (!mailchimpSettings.value.film_tour) return [];
    
    const filmTour = mailchimpSettings.value.film_tour;
    const year = new Date().getFullYear();
    
    // Sample location names for preview
    const sampleLocations = [
        'Melbourne South East - Classic Cinema',
        'Sydney - Opera House',
        'Brisbane Central - Convention Centre',
        'Adelaide - Entertainment Centre'
    ];
    
    const previewTags = [];
    
    sampleLocations.forEach(locationName => {
        // Extract everything before hyphen for both SHOW and SOURCE tags
        const locationTag = locationName.split(' - ')[0].toUpperCase();
        
        const showTag = `SHOW - ${locationTag}`;
        const sourceTag = `SOURCE - ${filmTour.toUpperCase()} ${locationTag} COMP ${year}`;
        
        previewTags.push({
            location: locationName,
            showTag: showTag,
            sourceTag: sourceTag
        });
    });
    
    return previewTags;
});

// Add new form for location creation
const locationForm = useForm({
    id: null,
    name: '',
    event_id: '',
    date: '',
    time: '',
    country: '',
    state: '',
    category: ''
});

// Add reactive refs for TBA checkboxes
const noDateYet = ref(false);
const noTimeYet = ref(false);

const formatLocationDateTime = (date, time) => {
    if (!date || !time) return '';
    
    // Handle TBA values
    if (date === 'TBA' || time === 'TBA') {
        let result = '';
        if (date === 'TBA' && time === 'TBA') {
            result = 'Date & Time TBA';
        } else if (date === 'TBA') {
            result = `Date TBA, ${time}`;
        } else if (time === 'TBA') {
            try {
                // Try to parse date - handle YYYY-MM-DD format or other formats
                let dateObj;
                if (date.match(/^\d{4}-\d{2}-\d{2}$/)) {
                    // Already in YYYY-MM-DD format
                    dateObj = new Date(date);
                } else {
                    // Try to parse other formats
                    dateObj = new Date(date);
                }
                
                if (isNaN(dateObj.getTime())) {
                    return `${date} - Time TBA`;
                }
                
            result = `${dateObj.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            })} - Time TBA`;
            } catch (e) {
                return `${date} - Time TBA`;
            }
        }
        return result;
    }

    try {
        // Parse date - handle YYYY-MM-DD format or other formats
        let dateObj;
        if (date.match(/^\d{4}-\d{2}-\d{2}$/)) {
            // Already in YYYY-MM-DD format - combine with time
            dateObj = new Date(`${date}T${time}`);
        } else {
            // Try to parse other formats
            // First try combining date and time
            dateObj = new Date(`${date}T${time}`);
            
            // If that fails, try parsing date alone
            if (isNaN(dateObj.getTime())) {
                dateObj = new Date(date);
                if (!isNaN(dateObj.getTime()) && time) {
                    // If date parsed successfully, try to add time
                    const [hours, minutes] = time.split(':').map(Number);
                    if (!isNaN(hours) && !isNaN(minutes)) {
                        dateObj.setHours(hours, minutes || 0);
                    }
                }
            }
        }
        
        // Check if date is valid
        if (isNaN(dateObj.getTime())) {
            // If date parsing failed, return a fallback format
            return `${date} ${time}`;
        }
        
        return dateObj.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
    } catch (e) {
        // Fallback to simple display if parsing fails
        return `${date} ${time}`;
    }
};

const generateLocationTags = (locationName) => {
    const tags = [];
    const filmTour = mailchimpSettings.value.film_tour;
    const year = new Date().getFullYear(); // Use next year by default
    
    // Extract everything before hyphen for both SHOW and SOURCE tags
    const locationTag = locationName.split(' - ')[0].toUpperCase();
    
    // Add SHOW tag
    tags.push(`SHOW - ${locationTag}`);
    
    // Add SOURCE tag with configured film tour code
    tags.push(`SOURCE - ${filmTour.toUpperCase()} ${locationTag} COMP ${year}`);
    
    return tags;
};

const handleMailchimpImport = async () => {
    console.log('handleMailchimpImport called');
    if (!selectedList.value) {
        Swal.fire('Error!', 'Please select a Mailchimp audience.', 'error');
        return;
    }

    isImporting.value = true;

    try {
        // First, get all subscribers
        console.log('Getting subscribers');
        const subscribersResponse = await axios.get(route('location.getSubscribers'), {
            params: {
                location_id: selectedLocation.value.id
            }
        });
        console.log('Subscribers response', subscribersResponse);   
        const allSubscribers = subscribersResponse.data.subscribers;
        const totalSubscribers = subscribersResponse.data.total;
        console.log('Total subscribers', totalSubscribers);
        if (totalSubscribers === 0) {
            Swal.fire('Error!', 'No subscribers found for this location.', 'error');
            isImporting.value = false;
            return;
        }
        console.log('Processing tags');

        // Generate location-specific tags
        const filmTour = mailchimpSettings.value.film_tour;
        const year = new Date().getFullYear();
        const locationName = selectedLocation.value.name;
        
        // Extract everything before hyphen for both SHOW and SOURCE tags
        const locationTag = locationName.split(' - ')[0].toUpperCase();
        
        // Create the SOURCE tag in the exact format
        const sourceTag = `SOURCE - ${filmTour.toUpperCase()} ${locationTag} COMP ${year}`;
        const showTag = `SHOW - ${locationTag}`;
        
        // Combine with any manual tags
        let allTags = [sourceTag, showTag];
        
        // Add any manual tags if they exist
        if (tags.value) {
            const manualTags = tags.value
                .split(',')
                .map(tag => tag.trim())
                .filter(tag => tag);
            allTags = [...allTags, ...manualTags];
        }
        
        console.log('Final tags for import:', allTags);

        // Adjust chunk size based on total subscribers
        const chunkSize = totalSubscribers <= 10 ? totalSubscribers : 10;
        const totalChunks = Math.ceil(allSubscribers.length / chunkSize);
        let successCount = 0;
        let failureCount = 0;
        let errors = [];
        let importedSubscribers = [];

        // Create and show progress modal
        Swal.fire({
            title: 'Importing Subscribers',
            html: `Processing 0 of ${totalSubscribers} subscribers...`,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Process each chunk
        for (let i = 0; i < totalChunks; i++) {
            const start = i * chunkSize;
            const end = Math.min(start + chunkSize, allSubscribers.length);
            const chunk = allSubscribers.slice(start, end);
            
            try {
                console.log('Posting to Mailchimp with tags:', allTags);
                const response = await axios.post('/location/import-data-to-mailchimp', {
                    subscribers: chunk,
                    list_id: selectedList.value,
                    tags: allTags,
                    location_id: selectedLocation.value.id
                });
                console.log('Mailchimp response', response);
                // Add successfully imported subscribers to the log
                if (response.data.details.success > 0) {
                    importedSubscribers = importedSubscribers.concat(chunk);
                }
                successCount += response.data.details.success;
                failureCount += response.data.details.failed;
                errors = errors.concat(response.data.details.errors);

                // Update progress
                const processed = Math.min((i + 1) * chunkSize, totalSubscribers);
                await Swal.update({
                    html: `Processing ${processed} of ${totalSubscribers} subscribers...<br>Success: ${successCount}, Failed: ${failureCount}`
                });
                console.log('Processed', processed);
            } catch (error) {
                console.error('Chunk import error:', error);
                console.error('Error response:', error.response);
                
                // Get detailed error message
                let errorMessage = error.message;
                if (error.response?.data?.message) {
                    errorMessage = error.response.data.message;
                } else if (error.response?.data?.errors) {
                    // Laravel validation errors
                    const validationErrors = Object.entries(error.response.data.errors)
                        .map(([field, messages]) => `${field}: ${Array.isArray(messages) ? messages.join(', ') : messages}`)
                        .join('; ');
                    errorMessage = `Validation failed: ${validationErrors}`;
                } else if (error.response?.data?.error) {
                    errorMessage = error.response.data.error;
                }
                
                errors.push(`Chunk ${i + 1} failed: ${errorMessage}`);
                failureCount += chunk.length;
            }
        }

        // Close progress modal
        await Swal.close();
        console.log('Progress modal closed');
        // Generate and download log file (with imported subscribers)
        await generateImportLog({
            totalSubscribers,
            successCount,
            failureCount,
            errors
        }, importedSubscribers);
        console.log('Log file generated');
        // Show final results with optimized error display
        let errorHtml = '';
        if (errors.length > 0) {
            errorHtml = '<br><br>Errors:<ul style="text-align:left;">' +
                errors.slice(0, 5).map(e => `<li>${e}</li>`).join('') +
                (errors.length > 5 ? '<li>...and more</li>' : '') +
                '</ul>';
        }
        Swal.fire({
            title: 'Import Completed',
            html: `Successfully imported ${successCount} out of ${totalSubscribers} subscribers.<br>Failed: ${failureCount}${errorHtml}<br><br>Import logs have been saved to the database and a detailed log file has been downloaded.`,
            icon: errors.length > 0 ? 'warning' : 'success'
        });
        console.log('Final results shown'); 
        // Close the Mailchimp modal and reset form
        showMailchimpModal.value = false;
        selectedList.value = '';
        tags.value = '';
        isImporting.value = false;
    } catch (error) {
        console.error('Import error:', error);
        await Swal.fire('Error!', error.response?.data?.error || 'Failed to import data to Mailchimp.', 'error');
        isImporting.value = false;
    }
};

// Helper function to sort locations by date and time
const sortLocationsByDateTime = (locations) => {
    return locations.sort((a, b) => {
        // Handle TBA dates - put them at the end
        if (a.date === 'TBA' && b.date !== 'TBA') return 1;
        if (a.date !== 'TBA' && b.date === 'TBA') return -1;
        if (a.date === 'TBA' && b.date === 'TBA') return 0;
        
        // Handle TBA times - put them at the end
        if (a.time === 'TBA' && b.time !== 'TBA') return 1;
        if (a.time !== 'TBA' && b.time === 'TBA') return -1;
        if (a.time === 'TBA' && b.time === 'TBA') return 0;
        
        // Try to create Date objects for comparison
        try {
            const dateA = new Date(`${a.date}T${a.time}`);
            const dateB = new Date(`${b.date}T${b.time}`);
            
            // Check if dates are valid
            if (isNaN(dateA.getTime()) && isNaN(dateB.getTime())) return 0;
            if (isNaN(dateA.getTime())) return 1;
            if (isNaN(dateB.getTime())) return -1;
            
            return dateA - dateB;
        } catch (e) {
            // If date parsing fails, compare as strings
            return `${a.date} ${a.time}`.localeCompare(`${b.date} ${b.time}`);
        }
    });
};

// ✅ Computed Property to Filter and Group Locations by Country
const locationsByCountry = computed(() => {
    let locations = props.locations;
    
    // Filter by search query if exists
    if (searchQuery.value) {
        locations = locations.filter(location =>
            location.name.toLowerCase().includes(searchQuery.value.toLowerCase())
        );
    }
    
    // Group by country
    const grouped = {};
    locations.forEach(location => {
        const country = location.country || 'Other';
        if (!grouped[country]) {
            grouped[country] = [];
        }
        grouped[country].push(location);
    });
    
    // Sort locations within each country by date and time
    Object.keys(grouped).forEach(country => {
        grouped[country] = sortLocationsByDateTime(grouped[country]);
    });
    
    // Sort countries alphabetically
    const sortedCountries = Object.keys(grouped).sort();
    const result = {};
    sortedCountries.forEach(country => {
        result[country] = grouped[country];
    });
    
    return result;
});

// ✅ Computed Property for Country List (for tabs)
const countryList = computed(() => {
    return Object.keys(locationsByCountry.value).sort();
});

// ✅ Computed Property for Available Categories
const availableCategories = computed(() => {
    const categories = new Set();
    if (selectedCountry.value && locationsByCountry.value[selectedCountry.value]) {
        locationsByCountry.value[selectedCountry.value].forEach(location => {
            if (location.category) {
                categories.add(location.category);
            }
        });
    }
    return Array.from(categories).sort();
});

// ✅ Computed Property for Selected Country Locations (filtered by category)
const selectedCountryLocations = computed(() => {
    if (!selectedCountry.value) {
        return [];
    }
    let locations = locationsByCountry.value[selectedCountry.value] || [];
    
    // Filter by category if selected
    if (selectedCategory.value) {
        locations = locations.filter(location => location.category === selectedCategory.value);
    }
    
    return locations;
});

// ✅ Initialize selected country on mount or when locations change
watch(
    () => locationsByCountry.value,
    (newValue) => {
        if (!selectedCountry.value && Object.keys(newValue).length > 0) {
            // Set first country as default
            selectedCountry.value = Object.keys(newValue).sort()[0];
        }
    },
    { immediate: true }
);
        
// ✅ Reset category filter when country changes
watch(
    () => selectedCountry.value,
    () => {
        selectedCategory.value = '';
    }
);

// ✅ Computed Property to Filter Locations (for backward compatibility)
const filteredLocations = computed(() => {
    let locations = props.locations;
    
    // Filter by search query if exists
    if (searchQuery.value) {
        locations = locations.filter(location =>
            location.name.toLowerCase().includes(searchQuery.value.toLowerCase())
        );
    }
    
    // Sort by date and time
    return sortLocationsByDateTime(locations);
});

const openPasswordModal = (location) => {
    selectedLocation.value = location;
    newPassword.value = '';
    confirmPassword.value = '';
    showPasswordModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('passwordModal'));
    modalElement.show();
};

const openEventPasswordModal = () => {
    eventNewPassword.value = '';
    eventConfirmPassword.value = '';
    showEventPasswordModal.value = true;
};

const generatePassword = (isEvent = false) => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let password = '';
    for (let i = 0; i < 8; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    if (isEvent) {
        eventNewPassword.value = password;
        eventConfirmPassword.value = password;
    } else {
        newPassword.value = password;
        confirmPassword.value = password;
        allLocationsNewPassword.value = password;
        allLocationsConfirmPassword.value = password;
    }
};

const updatePassword = async () => {
    if (!newPassword.value || !confirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Please fill in all fields'
        });
        return;
    }

    if (newPassword.value !== confirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Passwords do not match'
        });
        return;
    }

    try {
        await router.put(route('location.updatePassword', selectedLocation.value.id), {
            password: newPassword.value
        });

        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Password updated successfully',
            timer: 1500,
            showConfirmButton: false
        });
        showPasswordModal.value = false;
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to update password. Please try again.'
        });
    }
};

const copyPassword = (location, index) => {
    navigator.clipboard.writeText(location.password);
    copiedIndex.value = index;
    // Swal.fire({
    //     icon: 'success',
    //     title: 'Copied!',
    //     text: 'Password copied to clipboard',
    //     timer: 1500,
    //     showConfirmButton: false
    // });
    // setTimeout(() => {
    //     copiedIndex.value = null;
    // }, 2000);
};

const copyEventPassword = () => {
    navigator.clipboard.writeText(props.event.password);
    Swal.fire({
        icon: 'success',
        title: 'Copied!',
        text: 'Event password copied to clipboard',
        timer: 1500,
        showConfirmButton: false
    });
};

const allLocationsPage = () => {
    const url = route('pickawinner.alllocation', { event: props.event.id });
    window.open(url, '_blank', 'noopener');
};

const goToAttendeesPage = () => {
    router.get(route('attendees.index', { eventId: props.event.id }));
};

const viewLocationAttendees = (location) => {
    router.get(route('attendees.location', { eventId: props.event.id, locationId: location.id }));
};

const openEventbriteModal = (location) => {
    selectedLocation.value = location;
    eventbriteLink.value = '';
    eventbriteAttendees.value = [];
    eventbriteEventId.value = '';
    showEventbriteModal.value = true;
};

const openTicketReportModal = async (location) => {
    selectedLocation.value = location;
    isLoadingReport.value = true;
    showTicketReportModal.value = true;
    
    try {
        const response = await axios.get(route('location.ticketReport', location.id));
        ticketReportData.value = response.data;
    } catch (error) {
        console.error('Error fetching ticket report:', error);
        Swal.fire('Error', 'Failed to load ticket report', 'error');
        showTicketReportModal.value = false;
    } finally {
        isLoadingReport.value = false;
    }
};

const copyTicketSeparatedData = async () => {
    if (!ticketReportData.value || !selectedLocation.value) {
        await Swal.fire('Info', 'No ticket report data available to copy.', 'info');
        return;
    }

    isGeneratingTicketCopyData.value = true;

    try {
        // Load Mailchimp auto-sync settings so we can build TIX tags
        const settingsResponse = await axios.get(route('mailchimp.autosync.settings'), {
            params: {
                event_id: props.event.id
            }
        });

        const { settings } = settingsResponse.data;

        // Build TIX-specific tags:
        // SHOW - {LOCATION_TAG}
        // SOURCE - {FILM_TOUR} {LOCATION_TAG} TIX {YEAR}
        const tags = [];
        const filmTour = (settings.film_tour || 'WM').toString().toUpperCase();
        const year = new Date().getFullYear();
        const locationName = selectedLocation.value.name || '';
        const locationCountry = (selectedLocation.value.country || '').toString().trim();

        const locationTag = locationName.split(' - ')[0].toUpperCase();

        tags.push(`SHOW - ${locationTag}`);
        tags.push(`SOURCE - ${filmTour} ${locationTag} TIX ${year}`);

        // Add COUNTRY tag if we have a country on the location
        if (locationCountry) {
            const countryTag = `COUNTRY - ${locationCountry.toUpperCase()}`;
            tags.push(countryTag);
        }

        // Append any default tags from settings
        if (settings.default_tags && Array.isArray(settings.default_tags) && settings.default_tags.length > 0) {
            tags.push(...settings.default_tags);
        } else if (settings.default_tags && typeof settings.default_tags === 'string') {
            const defaultTagsArray = settings.default_tags
                .split(',')
                .map(tag => tag.trim())
                .filter(tag => tag);
            if (defaultTagsArray.length > 0) {
                tags.push(...defaultTagsArray);
            }
        }

        const summary = ticketReportData.value.summary || {};

        // Map ticket analytics into spreadsheet stats:
        // Total Collected Data  -> total ticket emails
        // NEW from Import       -> ticket only
        // Updated Data          -> duplicates (in both)
        // Rejected Data         -> 0 (not applicable for TIX analytics)
        const importData = {
            totalSubscribers: summary.ticket_emails_count || 0,
            newCount: summary.ticket_only_count || 0,
            updateCount: summary.duplicate_emails_count || 0,
            failureCount: 0,
            errors: [],
            errorDetails: [],
            rejectedFields: [],
            rejectedFieldsCount: 0
        };

        const response = await axios.post(route('location.generateSpreadsheetData'), {
            location_id: selectedLocation.value.id,
            import_data: importData,
            tags
        });

        const text = response.data.copy_paste_data || '';
        if (!text) {
            await Swal.fire('Info', 'No separated data was generated from the ticket report.', 'info');
            return;
        }

        // Extract only the actual tab-separated data line (skip helper text / emojis)
        let lineToCopy = text;
        const lines = text.split('\n');
        const dataLine = lines.find(l => l.includes('\t'));
        if (dataLine) {
            lineToCopy = dataLine;
        }

        await navigator.clipboard.writeText(lineToCopy);
        await Swal.fire('Copied', 'TIX separated data copied to clipboard. Paste it into your spreadsheet.', 'success');
    } catch (error) {
        console.error('Failed to generate/copy ticket spreadsheet data:', error);
        await Swal.fire('Error', 'Failed to generate separated data from the ticket report.', 'error');
    } finally {
        isGeneratingTicketCopyData.value = false;
    }
};

const openMailchimpReportModal = async (location) => {
    mailchimpReportData.value = null;
    isLoadingMailchimpReport.value = true;
    showMailchimpReportModal.value = true;

    try {
        const response = await axios.get(route('location.mailchimpReport', location.id));
        mailchimpReportData.value = response.data;
    } catch (error) {
        console.error('Error fetching Mailchimp location report:', error);
        await Swal.fire('Error', 'Failed to load Mailchimp import report.', 'error');
        showMailchimpReportModal.value = false;
    } finally {
        isLoadingMailchimpReport.value = false;
    }
};

const copyMailchimpSeparatedData = async () => {
    if (!mailchimpReportData.value || !mailchimpReportData.value.location) {
        await Swal.fire('Info', 'No Mailchimp report data available to copy.', 'info');
        return;
    }

    isGeneratingMailchimpCopyData.value = true;
    try {
        const summary = mailchimpReportData.value.summary || {};
        // Start with tags stored on the Mailchimp summary (usually COMP/import tags)
        let tags = Array.isArray(summary.tags) ? [...summary.tags] : [];

        // Also add a COUNTRY tag based on the location country, same as TIX copy
        const location = mailchimpReportData.value.location || {};
        const country = (location.country || '').toString().trim();
        if (country) {
            const countryTag = `COUNTRY - ${country.toUpperCase()}`;
            if (!tags.includes(countryTag)) {
                tags.push(countryTag);
            }
        }

        const importData = {
            totalSubscribers: summary.total_imports || 0,
            successCount: summary.successful_imports || 0,
            failureCount: summary.failed_imports || 0,
            updateCount: 0,
            newCount: summary.successful_imports || 0,
            errors: (mailchimpReportData.value.errors || []).map(e => e.error || ''),
            errorDetails: [],
            rejectedFields: [],
            rejectedFieldsCount: 0
        };

        const response = await axios.post(route('location.generateSpreadsheetData'), {
            location_id: mailchimpReportData.value.location.id,
            import_data: importData,
            tags
        });

        const text = response.data.copy_paste_data || '';
        if (!text) {
            await Swal.fire('Info', 'No separated data was generated.', 'info');
            return;
        }

        // Extract only the actual tab-separated data line (skip helper text / emojis)
        let lineToCopy = text;
        const lines = text.split('\n');
        const dataLine = lines.find(l => l.includes('\t'));
        if (dataLine) {
            lineToCopy = dataLine;
        }

        await navigator.clipboard.writeText(lineToCopy);
        await Swal.fire('Copied', 'Separated data copied to clipboard. Paste it into your spreadsheet.', 'success');
    } catch (error) {
        console.error('Failed to generate/copy Mailchimp spreadsheet data:', error);
        await Swal.fire('Error', 'Failed to generate separated data for this location.', 'error');
    } finally {
        isGeneratingMailchimpCopyData.value = false;
    }
};

const closeTicketReportModal = () => {
    showTicketReportModal.value = false;
    ticketReportData.value = null;
    selectedLocation.value = null;
};

const closeEventbriteModal = () => {
    showEventbriteModal.value = false;
    eventbriteLink.value = '';
    eventbriteAttendees.value = [];
    eventbriteEventId.value = '';
    selectedLocation.value = null;
};

const extractEventIdFromLink = (link) => {
    // Eventbrite links can be in various formats:
    // https://www.eventbrite.com/e/event-name-tickets-1234567890
    // https://eventbrite.com/e/event-name-tickets-1234567890
    // https://www.eventbrite.com/event/1234567890
    // https://eventbrite.com/event/1234567890
    
    try {
        // Try to extract from URL path
        const url = new URL(link);
        const pathParts = url.pathname.split('/');
        
        // Look for event ID in path (usually the last numeric part)
        for (let i = pathParts.length - 1; i >= 0; i--) {
            const part = pathParts[i];
            // Check if it's a numeric ID
            if (/^\d+$/.test(part)) {
                return part;
            }
            // Check if it ends with a numeric ID (e.g., "tickets-1234567890")
            const match = part.match(/-(\d+)$/);
            if (match) {
                return match[1];
            }
        }
        
        return null;
    } catch (error) {
        console.error('Error extracting event ID:', error);
        return null;
    }
};

const fetchEventbriteAttendees = async () => {
    if (!eventbriteLink.value.trim()) {
        Swal.fire('Error', 'Please enter an Eventbrite link', 'error');
        return;
    }

    const eventId = extractEventIdFromLink(eventbriteLink.value);
    if (!eventId) {
        Swal.fire('Error', 'Could not extract event ID from the link. Please check the link format.', 'error');
        return;
    }

    eventbriteEventId.value = eventId;
    isFetchingEventbrite.value = true;

    try {
        Swal.fire({
            title: 'Fetching Attendees',
            html: 'Please wait while we fetch attendee data from Eventbrite...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        const response = await axios.post(route('location.fetchEventbriteAttendees'), {
            event_id: eventId,
            location_id: selectedLocation.value.id
        });

        eventbriteAttendees.value = response.data.attendees;
        
        await Swal.close();
        
        if (eventbriteAttendees.value.length === 0) {
            Swal.fire('Info', 'No attendees found for this event.', 'info');
        } else {
            Swal.fire('Success', `Found ${eventbriteAttendees.value.length} unique attendees`, 'success');
        }
    } catch (error) {
        await Swal.close();
        console.error('Error fetching Eventbrite attendees:', error);
        Swal.fire('Error', error.response?.data?.error || 'Failed to fetch attendees from Eventbrite', 'error');
    } finally {
        isFetchingEventbrite.value = false;
    }
};

const exportEventbriteAttendees = () => {
    if (eventbriteAttendees.value.length === 0) {
        Swal.fire('Error', 'No attendees to export', 'error');
        return;
    }

    // Create CSV content
    const headers = ['Email', 'First Name', 'Last Name', 'Phone', 'City', 'State', 'Country'];
    const rows = eventbriteAttendees.value.map(attendee => [
        attendee.email || '',
        attendee.first_name || '',
        attendee.last_name || '',
        attendee.phone || '',
        attendee.city || '',
        attendee.state || '',
        attendee.country || ''
    ]);

    const csvContent = [
        headers.join(','),
        ...rows.map(row => row.map(cell => `"${cell}"`).join(','))
    ].join('\n');

    // Create and download file
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `eventbrite_attendees_${selectedLocation.value.name}_${new Date().toISOString().split('T')[0]}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    Swal.fire('Success', 'Attendees exported successfully', 'success');
};

const openEventbriteMailchimpModal = async () => {
    if (eventbriteAttendees.value.length === 0) {
        Swal.fire('Error', 'No attendees to import', 'error');
        return;
    }

    // Show loading spinner
    Swal.fire({
            title: 'Loading...',
            html: 'Preparing Mailchimp import...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

    try {
        // Get available accounts and settings
        const settingsResponse = await axios.get(route('mailchimp.autosync.settings'), {
            params: {
                event_id: props.event.id
            }
        });

        const { available_accounts, settings } = settingsResponse.data;
        eventbriteAvailableAccounts.value = available_accounts;
        
        // Set default account from settings
        eventbriteMailchimpAccount.value = settings.mailchimp_account || 'anz';
        
        // Generate default tags
        generateEventbriteDefaultTags(settings);
        
        // Load lists for default account
        await loadEventbriteLists(eventbriteMailchimpAccount.value);
        
        // Close loading spinner
        await Swal.close();
        
        showEventbriteMailchimpModal.value = true;
    } catch (error) {
        await Swal.close();
        console.error('Error opening Mailchimp modal:', error);
        Swal.fire('Error', 'Failed to load Mailchimp settings', 'error');
    }
};

const generateEventbriteDefaultTags = (settings) => {
    const tags = [];
    const filmTour = settings.film_tour || 'WM';
    const year = new Date().getFullYear();
    const locationName = selectedLocation.value.name;
    
    // Extract everything before hyphen for both SHOW and SOURCE tags
    const locationTag = locationName.split(' - ')[0].toUpperCase();
    
    // Add SHOW tag
    tags.push(`SHOW - ${locationTag}`);
    
    // Add SOURCE tag with configured film tour code (TIX instead of COMP)
    tags.push(`SOURCE - ${filmTour.toUpperCase()} ${locationTag} TIX ${year}`);
    
    // Add default tags from settings
    if (settings.default_tags && Array.isArray(settings.default_tags) && settings.default_tags.length > 0) {
        tags.push(...settings.default_tags);
    } else if (settings.default_tags && typeof settings.default_tags === 'string') {
        const defaultTagsArray = settings.default_tags
            .split(',')
            .map(tag => tag.trim())
            .filter(tag => tag);
        if (defaultTagsArray.length > 0) {
            tags.push(...defaultTagsArray);
        }
    }
    
    eventbriteDefaultTags.value = tags;
    eventbriteTags.value = tags.join(', ');
};

const loadEventbriteLists = async (account) => {
    if (!account) return;
    
    isLoadingEventbriteLists.value = true;
    eventbriteSelectedList.value = '';
    
    try {
        const response = await axios.get(route('mailchimp.autosync.lists'), {
            params: { account }
        });
        eventbriteMailchimpLists.value = response.data.lists;
    } catch (error) {
        console.error('Failed to load lists:', error);
        eventbriteMailchimpLists.value = [];
        Swal.fire('Error', 'Failed to load Mailchimp lists for this account', 'error');
    } finally {
        isLoadingEventbriteLists.value = false;
    }
};

const closeEventbriteMailchimpModal = () => {
    showEventbriteMailchimpModal.value = false;
    eventbriteMailchimpAccount.value = '';
    eventbriteSelectedList.value = '';
    eventbriteMailchimpLists.value = [];
    eventbriteTags.value = '';
    eventbriteDefaultTags.value = [];
};

const importEventbriteToMailchimp = async () => {
    // Validate before starting
    if (!eventbriteSelectedList.value || eventbriteSelectedList.value.trim() === '') {
        Swal.fire('Error', 'Please select a Mailchimp audience', 'error');
        return;
    }

    if (!eventbriteMailchimpAccount.value || eventbriteMailchimpAccount.value.trim() === '') {
        Swal.fire('Error', 'Please select a Mailchimp account', 'error');
        return;
    }

    isImportingEventbrite.value = true;

    try {
        // Capture values BEFORE closing modal (important! - closeEventbriteMailchimpModal resets them)
        const selectedListId = String(eventbriteSelectedList.value).trim();
        const selectedAccount = String(eventbriteMailchimpAccount.value).trim();
        
        // Double-check we have valid values
        if (!selectedListId || selectedListId === '' || !selectedAccount || selectedAccount === '') {
            Swal.fire('Error', 'Please select both Mailchimp account and audience', 'error');
            isImportingEventbrite.value = false;
            return;
        }
        
        console.log('Captured values for import:', {
            selectedListId,
            selectedAccount,
            location_id: selectedLocation.value.id,
            event_id: props.event.id
        });

        // Parse tags from the input field
        let tags = eventbriteTags.value
            .split(',')
            .map(tag => tag.trim())
            .filter(tag => tag);
        
        // Ensure tags is always an array (even if empty)
        if (!Array.isArray(tags) || tags.length === 0) {
            tags = [];
        }

        // Prepare attendees data (only first name, last name, email, phone)
        const subscribers = eventbriteAttendees.value.map(attendee => ({
            email_address: attendee.email,
            first_name: attendee.first_name || '',
            last_name: attendee.last_name || '',
            mobile_number: attendee.phone || ''
        }));

        const totalAttendees = subscribers.length;
        const chunkSize = totalAttendees <= 10 ? totalAttendees : 10;
        const totalChunks = Math.ceil(subscribers.length / chunkSize);
        let successCount = 0;
        let failureCount = 0;
        let updateCount = 0;
        let newCount = 0;
        let errors = [];
        let importedAttendees = [];
        let updatedAttendees = [];
        let newAttendees = [];
        let errorDetails = [];

        // Close the selection modal AFTER capturing values
        closeEventbriteMailchimpModal();

        // Show progress modal
        Swal.fire({
            title: 'Importing Attendees to Mailchimp',
            html: `
                <div class="text-left">
                    <div class="mb-3">
                        <div class="flex justify-between mb-1">
                            <span>Processing:</span>
                            <span class="font-semibold">0 / ${totalAttendees}</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm mt-3">
                        <div>✅ Success: <span id="success-count" class="font-semibold text-green-600">0</span></div>
                        <div>❌ Failed: <span id="failed-count" class="font-semibold text-red-600">0</span></div>
                        <div>🆕 New: <span id="new-count" class="font-semibold text-blue-600">0</span></div>
                        <div>🔄 Updated: <span id="update-count" class="font-semibold text-orange-600">0</span></div>
                    </div>
                </div>
            `,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Process each chunk
        for (let i = 0; i < totalChunks; i++) {
            const start = i * chunkSize;
            const end = Math.min(start + chunkSize, subscribers.length);
            const chunk = subscribers.slice(start, end);
            
            try {
                console.log('Sending chunk to Eventbrite import:', {
                    chunkSize: chunk.length,
                    location_id: selectedLocation.value.id,
                    event_id: props.event.id,
                    list_id: selectedListId,
                    mailchimp_account: selectedAccount,
                    tags: tags,
                    firstSubscriber: chunk[0]
                });
                
                // Validate we have required values
                if (!selectedListId || !selectedAccount) {
                    throw new Error(`Missing required values: list_id=${selectedListId}, account=${selectedAccount}`);
                }
                
                const response = await axios.post(route('location.importEventbriteToMailchimp'), {
                    subscribers: chunk,
                    location_id: parseInt(selectedLocation.value.id),
                    event_id: parseInt(props.event.id),
                    list_id: selectedListId,
                    mailchimp_account: selectedAccount,
                    tags: tags
                });

                // Process response details
                if (response.data.details.success > 0) {
                    importedAttendees = importedAttendees.concat(chunk);
                }

                if (response.data.details.updated !== undefined) {
                    updateCount += response.data.details.updated || 0;
                    // Track which attendees were updated
                    if (response.data.details.updated > 0) {
                        updatedAttendees = updatedAttendees.concat(chunk.slice(0, response.data.details.updated));
                    }
                }

                if (response.data.details.new !== undefined) {
                    newCount += response.data.details.new || 0;
                }

                successCount += response.data.details.success || 0;
                failureCount += response.data.details.failed || 0;
                errors = errors.concat(response.data.details.errors || []);

                // Update progress
                const processed = Math.min((i + 1) * chunkSize, totalAttendees);
                const progressPercent = (processed / totalAttendees) * 100;
                
                await Swal.update({
                    html: `
                        <div class="text-left">
                            <div class="mb-3">
                                <div class="flex justify-between mb-1">
                                    <span>Processing:</span>
                                    <span class="font-semibold">${processed} / ${totalAttendees}</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: ${progressPercent}%"></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm mt-3">
                                <div>✅ Success: <span id="success-count" class="font-semibold text-green-600">${successCount}</span></div>
                                <div>❌ Failed: <span id="failed-count" class="font-semibold text-red-600">${failureCount}</span></div>
                                <div>🆕 New: <span id="new-count" class="font-semibold text-blue-600">${newCount}</span></div>
                                <div>🔄 Updated: <span id="update-count" class="font-semibold text-orange-600">${updateCount}</span></div>
                            </div>
                        </div>
                    `
                });
            } catch (error) {
                console.error('Chunk import error:', error);
                errors.push(`Chunk ${i + 1} failed: ${error.message}`);
                failureCount += chunk.length;
            }
        }

        // Close progress modal
        await Swal.close();

        // Generate and download CSV file instead of log file
        if (importedAttendees.length > 0) {
            await downloadEventbriteImportCSV(importedAttendees, selectedLocation.value.name);
        }

        // Generate copy-paste data using the same service as regular import
        let copyPasteData = null;
        try {
            const importDataForSpreadsheet = {
                totalSubscribers: totalAttendees,
                successCount: successCount,
                failureCount: failureCount,
                updateCount: updateCount,
                newCount: newCount,
                errors: errors,
                errorDetails: errorDetails
            };
            
            const response = await axios.post(route('location.generateSpreadsheetData'), {
                location_id: selectedLocation.value.id,
                import_data: importDataForSpreadsheet,
                tags: tags
            });
            
            if (response.data.copy_paste_data) {
                copyPasteData = response.data.copy_paste_data;
            }
        } catch (error) {
            console.error('Failed to generate copy-paste data:', error);
        }

        // Show detailed results
        await showEventbriteDetailedResults({
            totalAttendees,
            successCount,
            failureCount,
            updateCount,
            newCount,
            errors,
            errorDetails,
            importedAttendees,
            updatedAttendees,
            copyPasteData,
            tags
        });

        // Close modal and reload page to update button color
        closeEventbriteModal();
        router.reload();

    } catch (error) {
        await Swal.close();
        console.error('Error importing to Mailchimp:', error);
        Swal.fire('Error', error.response?.data?.error || 'Failed to import attendees to Mailchimp', 'error');
    } finally {
        isImportingEventbrite.value = false;
    }
};

// Show detailed results modal for Eventbrite import
const showEventbriteDetailedResults = async (results) => {
    const {
        totalAttendees,
        successCount,
        failureCount,
        updateCount,
        newCount,
        errors,
        errorDetails,
        importedAttendees,
        updatedAttendees,
        copyPasteData,
        tags
    } = results;

    // Create tabs content - matching LocationAttendees.vue exactly
    const summaryTab = `
        <div class="text-left space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-blue-50 p-4 rounded-lg">
                    <h4 class="font-semibold text-blue-800 mb-2">📊 Import Summary</h4>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Total Processed:</span>
                            <span class="font-medium">${totalAttendees}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>✅ Successfully Imported:</span>
                            <span class="font-medium text-green-600">${successCount}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>❌ Failed:</span>
                            <span class="font-medium text-red-600">${failureCount}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>📈 Success Rate:</span>
                            <span class="font-medium">${totalAttendees > 0 ? ((successCount / totalAttendees) * 100).toFixed(1) : 0}%</span>
                        </div>
                    </div>
                </div>
                <div class="bg-green-50 p-4 rounded-lg">
                    <h4 class="font-semibold text-green-800 mb-2">🎯 Import Breakdown</h4>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>🆕 New Subscribers:</span>
                            <span class="font-medium text-blue-600">${newCount}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>🔄 Updated Existing:</span>
                            <span class="font-medium text-orange-600">${updateCount}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>📍 Location:</span>
                            <span class="font-medium">${selectedLocation.value.name}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>📋 Tags Applied:</span>
                            <span class="font-medium">${tags.length}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    const successTab = `
        <div class="text-left">
            <h4 class="font-semibold text-green-800 mb-3">✅ Successfully Imported (${successCount})</h4>
            <div class="max-h-60 overflow-y-auto">
                ${importedAttendees.length > 0 ? `
                    <div class="space-y-2">
                        ${importedAttendees.slice(0, 50).map((attendee, index) => `
                            <div class="bg-green-50 p-2 rounded text-sm">
                                <div class="font-medium">${attendee.first_name || 'N/A'} ${attendee.last_name || 'N/A'}</div>
                                <div class="text-gray-600">${attendee.email_address || 'No email'}</div>
                                ${attendee.mobile_number ? `<div class="text-gray-500">${attendee.mobile_number}</div>` : ''}
                            </div>
                        `).join('')}
                        ${importedAttendees.length > 50 ? `<div class="text-center text-gray-500 text-sm mt-2">... and ${importedAttendees.length - 50} more</div>` : ''}
                    </div>
                ` : '<div class="text-gray-500 text-center py-4">No successful imports</div>'}
            </div>
        </div>
    `;

    const updatesTab = `
        <div class="text-left">
            <h4 class="font-semibold text-orange-800 mb-3">🔄 Updated Subscribers (${updateCount})</h4>
            <div class="max-h-60 overflow-y-auto">
                ${updatedAttendees && updatedAttendees.length > 0 ? `
                    <div class="space-y-2">
                        ${updatedAttendees.slice(0, 50).map((attendee, index) => `
                            <div class="bg-orange-50 p-2 rounded text-sm">
                                <div class="font-medium">${attendee.first_name || 'N/A'} ${attendee.last_name || 'N/A'}</div>
                                <div class="text-gray-600">${attendee.email_address || 'No email'}</div>
                                <div class="text-orange-600 text-xs">Updated existing subscriber</div>
                            </div>
                        `).join('')}
                        ${updatedAttendees.length > 50 ? `<div class="text-center text-gray-500 text-sm mt-2">... and ${updatedAttendees.length - 50} more</div>` : ''}
                    </div>
                ` : '<div class="text-gray-500 text-center py-4">No existing subscribers were updated</div>'}
            </div>
        </div>
    `;

    const errorsTab = `
        <div class="text-left">
            <h4 class="font-semibold text-red-800 mb-3">❌ Import Errors (${failureCount})</h4>
            <div class="max-h-60 overflow-y-auto">
                ${errors.length > 0 ? `
                    <div class="space-y-2">
                        ${errors.slice(0, 20).map((error, index) => `
                            <div class="bg-red-50 p-2 rounded text-sm">
                                <div class="text-red-700">${error}</div>
                            </div>
                        `).join('')}
                        ${errors.length > 20 ? `<div class="text-center text-gray-500 text-sm mt-2">... and ${errors.length - 20} more errors</div>` : ''}
                    </div>
                ` : '<div class="text-gray-500 text-center py-4">No errors occurred</div>'}
            </div>
        </div>
    `;

    // Show the comprehensive results modal - matching LocationAttendees.vue exactly
    await Swal.fire({
        title: 'Import Results',
        html: `
            <div class="text-left">
                <div class="border-b border-gray-200 mb-4">
                    <nav class="-mb-px flex space-x-8">
                        <button onclick="showEventbriteTab('summary')" id="tab-summary" class="tab-button active border-b-2 border-blue-500 py-2 px-1 text-sm font-medium text-blue-600">
                            📊 Summary
                        </button>
                        <button onclick="showEventbriteTab('success')" id="tab-success" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            ✅ Success (${successCount})
                        </button>
                        <button onclick="showEventbriteTab('updates')" id="tab-updates" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            🔄 Updates (${updateCount})
                        </button>
                        <button onclick="showEventbriteTab('errors')" id="tab-errors" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            ❌ Errors (${failureCount})
                        </button>
                    </nav>
                </div>
                <div id="tab-content-summary" class="tab-content">${summaryTab}</div>
                <div id="tab-content-success" class="tab-content hidden">${successTab}</div>
                <div id="tab-content-updates" class="tab-content hidden">${updatesTab}</div>
                <div id="tab-content-errors" class="tab-content hidden">${errorsTab}</div>
                <div class="mt-4 text-center">
                    <div class="text-sm text-gray-600">
                        📥 A CSV file has been downloaded with the imported attendees.
                    </div>
                </div>
                
                <!-- Copy-Paste Data Section -->
                <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                    <h4 class="font-semibold text-gray-800 mb-3">📋 Spreadsheet Data</h4>
                    <div class="mb-4 p-3 bg-white border rounded-lg">
                        <div class="text-sm text-gray-600 mb-2">Copy-paste data for spreadsheet:</div>
                        <div class="select-all cursor-pointer hover:bg-gray-100 transition-colors p-2 bg-gray-50 rounded font-mono text-xs whitespace-pre-wrap" id="spreadsheet-data">${copyPasteData || 'No data available'}</div>
                    </div>
                    <div class="flex justify-center">
                        <button onclick="copyEventbriteTabSeparated()" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg text-sm font-medium transition-colors shadow-md">
                            📋 Copy Separated Data
                        </button>
                    </div>
                </div>
            </div>
        `,
        width: '800px',
        confirmButtonText: 'Close',
        confirmButtonColor: '#059669',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            // Add tab switching functionality
            window.showEventbriteTab = (tabName) => {
                // Hide all tab content
                document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
                document.querySelectorAll('.tab-button').forEach(el => {
                    el.classList.remove('active', 'border-blue-500', 'text-blue-600');
                    el.classList.add('border-transparent', 'text-gray-500');
                });
                
                // Show selected tab
                const contentEl = document.getElementById('tab-content-' + tabName);
                if (contentEl) {
                    contentEl.classList.remove('hidden');
                }
                const button = document.getElementById('tab-' + tabName);
                if (button) {
                    button.classList.add('active', 'border-blue-500', 'text-blue-600');
                    button.classList.remove('border-transparent', 'text-gray-500');
                }
            };
            
            // Add click handler for spreadsheet data selection
            const spreadsheetDataElement = document.getElementById('spreadsheet-data');
            if (spreadsheetDataElement) {
                spreadsheetDataElement.addEventListener('click', function() {
                    const range = document.createRange();
                    range.selectNodeContents(this);
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                });
            }
            
            // Add copy function - matching LocationAttendees.vue logic
            window.copyEventbriteTabSeparated = function() {
                const dataElement = document.getElementById('spreadsheet-data');
                const copyButton = document.querySelector('button[onclick="copyEventbriteTabSeparated()"]');
                
                if (dataElement) {
                    const text = dataElement.textContent;
                    const lines = text.split('\n');
                    
                    // Find the line that contains "COPY THIS LINE"
                    const copyLineIndex = lines.findIndex(line => line.includes('COPY THIS LINE'));
                    if (copyLineIndex !== -1 && copyLineIndex + 1 < lines.length) {
                        // Get the line after "COPY THIS LINE" which contains the tab-separated data
                        const tabLine = lines[copyLineIndex + 1].trim();
                        const hasTabs = tabLine.includes('\t');
                        const hasCommas = tabLine.includes(',');
                        const tabSplit = tabLine.split('\t');
                        const commaSplit = tabLine.split(',');
                        
                        let dataToCopy = tabLine;
                        
                        if (hasTabs && tabSplit.length > 1) {
                            dataToCopy = tabLine;
                        } else if (hasCommas && commaSplit.length > 1) {
                            // Convert comma-separated to tab-separated
                            dataToCopy = commaSplit.join('\t');
                        }
                        
                        navigator.clipboard.writeText(dataToCopy).then(() => {
                            // Change button to show checkmark
                            if (copyButton) {
                                const originalHTML = copyButton.innerHTML;
                                copyButton.innerHTML = '✅ Copied!';
                                copyButton.classList.remove('bg-blue-500', 'hover:bg-blue-600');
                                copyButton.classList.add('bg-green-500', 'hover:bg-green-600');
                                copyButton.disabled = true;
                                
                                // Reset after 2 seconds
                                setTimeout(() => {
                                    copyButton.innerHTML = originalHTML;
                                    copyButton.classList.remove('bg-green-500', 'hover:bg-green-600');
                                    copyButton.classList.add('bg-blue-500', 'hover:bg-blue-600');
                                    copyButton.disabled = false;
                                }, 2000);
                            }
                        }).catch(err => {
                            console.error('Failed to copy to clipboard:', err);
                            Swal.fire({
                                title: 'Copy Failed',
                                html: `
                                    <div class="text-left">
                                        <p class="mb-3">Please manually copy this data:</p>
                                        <div class="bg-gray-100 p-3 rounded text-sm font-mono break-all">
                                            ${dataToCopy}
                                        </div>
                                    </div>
                                `,
                                confirmButtonText: 'OK',
                                allowOutsideClick: false,
                                allowEscapeKey: false
                            });
                        });
                    } else {
                        // Fallback: copy the entire content
                        navigator.clipboard.writeText(text).then(() => {
                            // Change button to show checkmark
                            if (copyButton) {
                                const originalHTML = copyButton.innerHTML;
                                copyButton.innerHTML = '✅ Copied!';
                                copyButton.classList.remove('bg-blue-500', 'hover:bg-blue-600');
                                copyButton.classList.add('bg-green-500', 'hover:bg-green-600');
                                copyButton.disabled = true;
                                
                                // Reset after 2 seconds
                                setTimeout(() => {
                                    copyButton.innerHTML = originalHTML;
                                    copyButton.classList.remove('bg-green-500', 'hover:bg-green-600');
                                    copyButton.classList.add('bg-blue-500', 'hover:bg-blue-600');
                                    copyButton.disabled = false;
                                }, 2000);
                            }
                        }).catch(err => {
                            console.error('Failed to copy:', err);
                        });
                    }
                }
            };
            
        }
    });
};


// Function to extract state from location name (same logic as backend)
const extractStateFromName = (name) => {
    if (!name) return '';
    
    // Check if name contains " - " (separator for cinema)
    let locationPart = name;
    if (name.includes(' - ')) {
        const parts = name.split(' - ', 2);
        locationPart = parts[0].trim();
    }
    
    // Check if location part contains state (2-3 letter abbreviation at the end)
    // Pattern: "Location ST" where ST is 2-3 uppercase letters
    const stateMatch = locationPart.match(/^(.+?)\s+([A-Z]{2,3})$/);
    if (stateMatch) {
        return stateMatch[2].trim();
    }
    
    return '';
};

// Function to extract location name without state
const extractLocationName = (name) => {
    if (!name) return '';
    
    // Check if name contains " - " (separator for cinema)
    let locationPart = name;
    let cinema = '';
    if (name.includes(' - ')) {
        const parts = name.split(' - ', 2);
        locationPart = parts[0].trim();
        cinema = parts[1]?.trim() || '';
    }
    
    // Remove state if present (2-3 letter abbreviation at the end)
    const stateMatch = locationPart.match(/^(.+?)\s+([A-Z]{2,3})$/);
    if (stateMatch) {
        locationPart = stateMatch[1].trim();
    }
    
    // Reconstruct name without state
    if (cinema) {
        return locationPart + ' - ' + cinema;
    }
    return locationPart;
};

// Function to open location creation modal
const openLocationModal = (location = null) => {
    locationForm.reset();
    noDateYet.value = false;
    noTimeYet.value = false;
    
    if (location) {
        isEditing.value = true;
        locationForm.id = location.id;
        // Extract state and location name separately
        locationForm.name = extractLocationName(location.name);
        locationForm.state = extractStateFromName(location.name);
        locationForm.date = location.date;
        locationForm.time = location.time;
        locationForm.country = location.country || '';
        locationForm.category = location.category || '';
        locationForm.event_id = props.event.id;
        
        // Set checkboxes based on existing values
        noDateYet.value = location.date === 'TBA';
        noTimeYet.value = location.time === 'TBA';
    } else {
        isEditing.value = false;
        locationForm.event_id = props.event.id;
        locationForm.country = '';
        locationForm.state = '';
        locationForm.category = '';
    }
    showLocationModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('createLocationModal'));
    modalElement.show();
};

// Function to save location
const saveLocation = () => {
    if (!locationForm.name) {
        Swal.fire('Error', 'Location name is required!', 'error');
        return;
    }

    // Build the name: "Location State (if any) - Cinema" (same logic as master sheet)
    let locationName = locationForm.name.trim();
    const state = locationForm.state ? locationForm.state.trim().toUpperCase() : '';
    
    // Check if name already contains " - " (cinema separator)
    let hasCinema = false;
    let cinema = '';
    if (locationName.includes(' - ')) {
        const parts = locationName.split(' - ', 2);
        locationName = parts[0].trim();
        cinema = parts[1].trim();
        hasCinema = true;
    }
    
    // Build final name: add state if provided, then add cinema if exists
    let finalName = locationName;
    if (state) {
        finalName += ' ' + state;
    }
    if (hasCinema && cinema) {
        finalName += ' - ' + cinema;
    }

    // Prepare form data with TBA values if checkboxes are checked
    const formData = {
        ...locationForm,
        name: finalName, // Use the built name with state
        date: noDateYet.value ? 'TBA' : locationForm.date,
        time: noTimeYet.value ? 'TBA' : locationForm.time
    };

    // Validate that if checkboxes are not checked, date and time are provided
    if (!noDateYet.value && !formData.date) {
        Swal.fire('Error', 'Date is required or check "No Date yet"!', 'error');
        return;
    }
    if (!noTimeYet.value && !formData.time) {
        Swal.fire('Error', 'Time is required or check "No Time yet"!', 'error');
        return;
    }

    if (isEditing.value) {
        router.put(route('location.update', locationForm.id), formData, {
            onSuccess: () => {
                closeLocationModal();
                Swal.fire('Success!', 'Location has been updated.', 'success');
                locationForm.reset();
                router.reload();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue updating the location.', 'error');
            }
        });
    } else {
        router.post(route('location.store'), formData, {
            onSuccess: () => {
                closeLocationModal();
                Swal.fire('Success!', 'Location has been created.', 'success');
                locationForm.reset();
                router.reload();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue creating the location.', 'error');
            }
        });
    }
};



// Add function to handle modal close
const closeLocationModal = () => {
    showLocationModal.value = false;
    noDateYet.value = false;
    noTimeYet.value = false;
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('createLocationModal'));
    if (modalElement) {
        modalElement.hide();
        // Remove the backdrop
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        // Remove the modal-open class from body
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
};

// Add function to handle password modal close
const closePasswordModal = () => {
    showPasswordModal.value = false;
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('passwordModal'));
    if (modalElement) {
        modalElement.hide();
        // Remove the backdrop
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        // Remove the modal-open class from body
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
};

const updateAllPasswords = async () => {
    if (!allLocationsNewPassword.value || !allLocationsConfirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Please fill in all fields'
        });
        return;
    }

    if (allLocationsNewPassword.value.length < 8) {
        Swal.fire({
            icon: 'error',
            title: 'Password Too Short',
            text: 'Password must be at least 8 characters long'
        });
        return;
    }

    if (allLocationsNewPassword.value !== allLocationsConfirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Passwords do not match'
        });
        return;
    }

    try {
        await router.put(route('location.updateAllPasswords', props.event.id), {
            password: allLocationsNewPassword.value
        });

        // Close the modal first
        const modalElement = bootstrap.Modal.getInstance(document.getElementById('allPasswordsModal'));
        if (modalElement) {
            modalElement.hide();
        }
        
        // Show success message
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'All location passwords updated successfully',
            timer: 1500,
            showConfirmButton: false
        }).then(() => {
            // Reload the page after the success message
            router.reload();
        });
    } catch (error) {
        console.error('Update error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to update passwords. Please try again.'
        });
    }
};

const openAllPasswordsModal = () => {
    allLocationsNewPassword.value = '';
    allLocationsConfirmPassword.value = '';
    showAllPasswordsModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('allPasswordsModal'));
    modalElement.show();
};

const closeAllPasswordsModal = () => {
    showAllPasswordsModal.value = false;
    allLocationsNewPassword.value = '';
    allLocationsConfirmPassword.value = '';
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('allPasswordsModal'));
    if (modalElement) {
        modalElement.hide();
        // Remove the backdrop
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        // Remove the modal-open class from body
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
};

const openMailchimpSettingsModal = async () => {
    isSettingsLoading.value = true;
    try {
        const response = await axios.get(route('mailchimp.autosync.settings'), {
            params: {
                event_id: props.event.id
            }
        });
        const { settings, available_lists, available_accounts } = response.data;
        
        mailchimpSettings.value = {
            ...settings,
            film_tour: settings.film_tour,
            default_tags: Array.isArray(settings.default_tags) ? settings.default_tags.join(', ') : '',
            mailchimp_account: settings.mailchimp_account || 'anz',
            event_id: props.event.id
        };
        availableLists.value = available_lists;
        availableAccounts.value = available_accounts;
        showMailchimpSettingsModal.value = true;
    } catch (error) {
        console.error('Failed to fetch Mailchimp settings:', error);
        Swal.fire('Error', 'Failed to load Mailchimp settings', 'error');
    } finally {
        isSettingsLoading.value = false;
    }
};

const loadListsForAccount = async (account) => {
    try {
        const response = await axios.get(route('mailchimp.autosync.lists'), {
            params: { account }
        });
        availableLists.value = response.data.lists;
        // Reset selected list when account changes
        mailchimpSettings.value.default_list_id = '';
    } catch (error) {
        console.error('Failed to load lists for account:', error);
        // Don't show error modal for account changes, just log it
        console.warn('Account not configured or API error:', error.message);
        availableLists.value = [];
    }
};

const saveMailchimpSettings = async () => {
    try {
        const settings = {
            ...mailchimpSettings.value,
            film_tour: mailchimpSettings.value.film_tour,
            default_tags: mailchimpSettings.value.default_tags.split(',').map(tag => tag.trim()).filter(tag => tag),
            enabled_locations: Array.isArray(mailchimpSettings.value.enabled_locations) 
                ? mailchimpSettings.value.enabled_locations 
                : [],
            mailchimp_account: mailchimpSettings.value.mailchimp_account,
            event_id: props.event.id
        };

        console.log('Saving settings:', settings);

        await axios.post(route('mailchimp.autosync.update'), settings);
        
        showMailchimpSettingsModal.value = false;
        
        Swal.fire('Success', 'Mailchimp auto-sync settings updated successfully', 'success');
    } catch (error) {
        console.error('Failed to save Mailchimp settings:', error);
        Swal.fire('Error', 'Failed to save Mailchimp settings', 'error');
    }
};

// Generate import log file
const generateImportLog = async (stats, subscribers) => {
    try {
        const logContent = generateLogContent(stats, subscribers);
        downloadLogFile(logContent, `mailchimp-import-${selectedLocation.value.name}-${new Date().toISOString().split('T')[0]}.log`);
    } catch (error) {
        console.error('Failed to generate log file:', error);
    }
};

const generateLogContent = (stats, subscribers) => {
    const timestamp = new Date().toISOString();
    const locationName = selectedLocation.value.name;
    
    let content = `=== Mailchimp Import Log ===\n`;
    content += `Timestamp: ${timestamp}\n`;
    content += `Location: ${locationName}\n`;
    content += `Mailchimp List ID: ${selectedList.value}\n`;
    content += `Tags Applied: ${tags.value || 'None'}\n`;
    content += `\n=== Import Statistics ===\n`;
    content += `Total Subscribers: ${stats.totalSubscribers}\n`;
    content += `Successfully Imported: ${stats.successCount}\n`;
    content += `Failed Imports: ${stats.failureCount}\n`;
    content += `Success Rate: ${((stats.successCount / stats.totalSubscribers) * 100).toFixed(2)}%\n`;
    
    if (stats.errors && stats.errors.length > 0) {
        content += `\n=== Import Errors ===\n`;
        stats.errors.forEach((error, index) => {
            content += `${index + 1}. ${error}\n`;
        });
    }
    
    if (subscribers && subscribers.length > 0) {
        content += `\n=== Successfully Imported Subscribers ===\n`;
        subscribers.forEach((subscriber, index) => {
            content += `${index + 1}. ${subscriber.first_name || ''} ${subscriber.last_name || ''} (${subscriber.email_address || 'No email'})\n`;
        });
    }
    
    content += `\n=== Field Mappings Used ===\n`;
    content += `First Name: FNAME | MERGE1\n`;
    content += `Last Name: LNAME | MERGE2\n`;
    content += `Email Address: EMAIL | MERGE0\n`;
    content += `Street Address: MMERGE10 | MERGE10\n`;
    content += `City: CITY | MERGE3\n`;
    content += `State: STATE | MERGE6\n`;
    content += `Zip Code: ZIPCODE | MERGE7\n`;
    content += `Country: COUNTRY | MERGE8\n`;
    content += `Mobile Number: PHONE | MERGE4\n`;
    content += `SMS Phone: SMSPHONE | MERGE30\n`;
    content += `Age: MMERGE14 | MERGE14\n`;
    content += `Gender: GENDER | MERGE17\n`;
    
    return content;
};

const downloadLogFile = (content, filename) => {
    const blob = new Blob([content], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
};

// Download CSV file for Eventbrite import
const downloadEventbriteImportCSV = (attendees, locationName) => {
    try {
        // Create CSV content
        const headers = ['Email', 'First Name', 'Last Name', 'Phone'];
        const rows = attendees.map(attendee => [
            attendee.email_address || '',
            attendee.first_name || '',
            attendee.last_name || '',
            attendee.mobile_number || ''
        ]);

        const csvContent = [
            headers.join(','),
            ...rows.map(row => row.map(cell => `"${cell}"`).join(','))
        ].join('\n');

        // Create and download file
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `eventbrite-import-${locationName.replace(/[^a-z0-9]/gi, '_')}-${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } catch (error) {
        console.error('Failed to generate CSV file:', error);
    }
};



// Toggle location selection for export
const toggleLocationSelection = (location) => {
    const index = selectedLocationsForExport.value.findIndex(loc => loc.id === location.id);
    if (index > -1) {
        selectedLocationsForExport.value.splice(index, 1);
    } else {
        selectedLocationsForExport.value.push(location);
    }
};

// Check if location is selected
const isLocationSelected = (location) => {
    return selectedLocationsForExport.value.some(loc => loc.id === location.id);
};

// Export selected locations
const exportSelectedLocations = async () => {
    if (selectedLocationsForExport.value.length === 0) {
        Swal.fire('Error', 'Please select at least one location to export', 'error');
        return;
    }

    try {
        Swal.fire({
            title: 'Exporting Locations',
            html: `Exporting ${selectedLocationsForExport.value.length} location(s)...`,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Export each location as a separate file
        for (let i = 0; i < selectedLocationsForExport.value.length; i++) {
            const location = selectedLocationsForExport.value[i];
            const exportUrl = route('location.export', { locationId: location.id });
            
            // Open in new window to trigger download
            window.open(exportUrl, '_blank');
            
            // Small delay between downloads to avoid browser blocking
            if (i < selectedLocationsForExport.value.length - 1) {
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        }

        await Swal.close();
        
        Swal.fire({
            title: 'Export Complete',
            text: `Successfully exported ${selectedLocationsForExport.value.length} location(s). Check your downloads folder.`,
            icon: 'success',
            timer: 3000,
            showConfirmButton: false
        });

        // Clear selection after export
        selectedLocationsForExport.value = [];
    } catch (error) {
        await Swal.close();
        console.error('Export error:', error);
        Swal.fire('Error', 'Failed to export locations. Please try again.', 'error');
    }
};

// Add watch for flash messages
watch(
    () => props.flash,
    (flash) => {
        if (flash?.success) {
            Swal.fire({
                title: 'Success!',
                text: flash.success,
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });
        }
        if (flash?.error) {
            Swal.fire({
                title: 'Error!',
                text: flash.error,
                icon: 'error'
            });
        }
    },
    { immediate: true, deep: true }
);

// Watch for account changes to load lists
watch(
    () => mailchimpSettings.value.mailchimp_account,
    (newAccount) => {
        if (newAccount && showMailchimpSettingsModal.value) {
            loadListsForAccount(newAccount);
        }
    }
);

// Watch for Eventbrite Mailchimp account changes
watch(
    () => eventbriteMailchimpAccount.value,
    (newAccount) => {
        if (newAccount && showEventbriteMailchimpModal.value) {
            loadEventbriteLists(newAccount);
        }
    }
);

</script>

<template>
    <Head title="Pick a Winner Page" />

    <AuthenticatedLayout>
        <div class="p-4">
            <div class="mx-auto max-w-5xl">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center">
                        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 mb-4">
                            <div class="text-center sm:text-left flex items-center gap-2">
                                <!-- All Locations Page Button -->
                                <button 
                                    target="_blank"
                                    @click="allLocationsPage"
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="View All Locations Attendees"
                                >
                                    National Tour Wide Prizes
                                </button>
                                </div>

                            <h3 class="text-lg font-semibold">{{ event.event_name }}</h3>
                            <div class="flex gap-2">
                                <!-- Add Location Button -->
                                <button 
                                    class="bg-black text-white px-4 py-2 rounded hover:bg-black-700"
                                    @click="openLocationModal()"
                                >
                                    <i class="fa-solid fa-plus"></i> Add Location
                                </button>
                                <!-- Mailchimp Settings Button -->
                                <button 
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    @click="openMailchimpSettingsModal"
                                    title="Mailchimp Auto-Sync Settings"
                                    :disabled="isSettingsLoading"
                                >
                                    <i v-if="!isSettingsLoading" class="fa-solid fa-gear"></i>
                                    <i v-else class="fa-solid fa-spinner fa-spin"></i>
                                </button>
                                <!-- Update All Passwords Button -->
                                <button 
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    @click="openAllPasswordsModal"
                                >
                                    <i class="fa-solid fa-key"></i> 
                                </button>
                                <!-- Attendees Database Button -->
                                <!-- <button 
                                    @click="goToAttendeesPage"
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="View Attendees Database"
                                >
                                    <i class="fa-solid fa-database"></i>
                                </button> -->
                            </div>
                        </div>
                        <!-- <div class="d-flex justify-content-between items-center mb-4">
                            <button class="btn btn-primary" @click="openEventPasswordModal">
                                <i class="fa-solid fa-key"></i> Event Password
                            </button>
                        </div> -->
                        <!-- ✅ Search Bar and Category Filter -->
                        <div class="mb-4 space-y-3">
                        <input 
                            v-model="searchQuery" 
                            type="text" 
                            placeholder="Search location..."
                                class="w-full border p-2 rounded focus:ring focus:ring-blue-300"
                        />

                            <!-- Category Filter -->
                            <div v-if="selectedCountry && availableCategories.length > 0" class="flex items-center gap-2">
                                <label class="text-sm font-medium text-gray-700 whitespace-nowrap">Filter by Category:</label>
                                <select 
                                    v-model="selectedCategory"
                                    class="border rounded px-3 py-2 text-sm focus:ring focus:ring-blue-300"
                                    style="min-width: 200px;"
                                >
                                    <option value="">All Categories</option>
                                    <option 
                                        v-for="category in availableCategories" 
                                        :key="category" 
                                        :value="category"
                                    >
                                        {{ category }}
                                    </option>
                                </select>
                                <button 
                                    v-if="selectedCategory"
                                    @click="selectedCategory = ''"
                                    class="px-3 py-2 text-sm text-gray-600 hover:text-gray-800 underline"
                                >
                                    Clear Filter
                                </button>
                                <!-- Export Selected Locations Button -->
                                <button 
                                    v-if="selectedLocationsForExport.length > 0"
                                    style="background-color: #17a2b8; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    @click="exportSelectedLocations"
                                    :title="`Export ${selectedLocationsForExport.length} selected location(s)`"
                                >
                                    <i class="fa-solid fa-file-export"></i> Export Selected ({{ selectedLocationsForExport.length }})
                                </button>
                                </div>
                        </div>

                        <!-- ✅ Country Tabs -->
                        <div v-if="countryList.length > 0" class="mb-4">
                            <div class="border-b border-gray-200">
                                <nav class="-mb-px flex space-x-4 overflow-x-auto" style="flex-wrap: wrap;">
                                <button 
                                        v-for="country in countryList"
                                        :key="country"
                                        @click="selectedCountry = country"
                                        :class="[
                                            'px-4 py-2 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                                            selectedCountry === country
                                                ? 'border-blue-500 text-blue-600'
                                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                        ]"
                                        :style="selectedCountry === country ? 'background-color: #EFF6FF;' : ''"
                                    >
                                        {{ country }}
                                        <span class="ml-2 px-2 py-0.5 rounded-full text-xs"
                                            :style="selectedCountry === country 
                                                ? 'background-color: #3B82F6; color: white;' 
                                                : 'background-color: #E5E7EB; color: #6B7280;'"
                                        >
                                            {{ locationsByCountry[country].length }}
                                        </span>
                                    </button>
                                </nav>
                            </div>
                        </div>

                        <!-- ✅ Filter Info -->
                        <div v-if="selectedCountry" class="mb-3 text-sm text-gray-600">
                            <span v-if="selectedCategory">
                                Showing {{ selectedCountryLocations.length }} location(s) in <strong>{{ selectedCountry }}</strong> with category <strong>{{ selectedCategory }}</strong>
                            </span>
                            <span v-else>
                                Showing {{ selectedCountryLocations.length }} location(s) in <strong>{{ selectedCountry }}</strong>
                            </span>
                        </div>

                        <!-- ✅ Locations List for Selected Country -->
                        <div v-if="selectedCountry && selectedCountryLocations.length > 0" class="space-y-2">
                            <div 
                                v-for="(location, index) in selectedCountryLocations" 
                                :key="`${selectedCountry}-${index}`" 
                                class="flex items-center space-x-2 w-full"
                            >
                                <!-- Checkbox for selection -->
                                <input 
                                    type="checkbox"
                                    :checked="isLocationSelected(location)"
                                    @change="toggleLocationSelection(location)"
                                    class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer"
                                    style="min-width: 20px;"
                                />
                                <div 
                                    @click="viewLocationAttendees(location)"
                                    class="px-4 py-2 rounded w-full text-left" 
                                    style="background-color: white; border: 2px solid black; font-weight: bold; color: black; border-radius: 5px; padding: 10px 20px; cursor: pointer; transition: background-color 0.2s;"
                                    @mouseenter="$event.target.style.backgroundColor = '#f3f4f6'"
                                    @mouseleave="$event.target.style.backgroundColor = 'white'"
                                    :title="location.imported_to_mailchimp ? 'View Attendees (Imported to Mailchimp)' : 'View Attendees'"
                                >
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <div class="font-semibold">{{ location.name }}</div>
                                            <div class="text-sm font-normal text-gray-600 mt-1">
                                                {{ formatLocationDateTime(location.date, location.time) }}
                                            </div>
                                        </div>
                                        <!-- Category Badge -->
                                        <div v-if="location.category" class="ml-3">
                                            <span 
                                                class="px-3 py-1 rounded-full text-xs font-semibold"
                                                style="background-color: #16C3D9; color: white;"
                                            >
                                                {{ location.category }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Edit Button -->
                                <button 
                                    @click="openLocationModal(location)"
                                    class="text-white px-3 py-2 rounded"
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="Edit Location"
                                >
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <!-- Password Button -->
                                <button 
                                    @click="openPasswordModal(location)"
                                    class="text-white px-3 py-2 rounded"
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="View Password"
                                >
                                    <i class="fa-solid fa-key"></i>
                                </button>
                                <!-- Ticket Report Button -->
                                <button 
                                    v-if="location.imported_eventbrite"
                                    @click="openTicketReportModal(location)"
                                    class="text-white px-3 py-2 rounded"
                                    style="background-color: #6c757d; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="View Ticket Report"
                                >
                                    <i class="fa-solid fa-chart-bar"></i>
                                </button>
                                <!-- Mailchimp Import Report Button -->
                                <button
                                    v-if="location.imported_to_mailchimp"
                                    @click="openMailchimpReportModal(location)"
                                    class="text-white px-3 py-2 rounded"
                                    style="background-color: #6c757d; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="View Mailchimp Import Report"
                                >
                                    <i class="fa-solid fa-chart-pie"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ✅ If No Locations Found -->
                        <div v-else-if="selectedCountry && selectedCountryLocations.length === 0">
                            <p class="text-gray-600 mt-3">
                                <span v-if="selectedCategory">
                                    No locations found in <strong>{{ selectedCountry }}</strong> with category <strong>{{ selectedCategory }}</strong>.
                                </span>
                                <span v-else>
                                    No locations found in <strong>{{ selectedCountry }}</strong>.
                                </span>
                            </p>
                        </div>
                        <div v-else-if="!selectedCountry">
                            <p class="text-gray-600 mt-3">Please select a country to view locations.</p>
                        </div>
                        <div v-else>
                            <p class="text-gray-600 mt-3">No locations found.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mailchimp Import Report Modal -->
        <div v-if="showMailchimpReportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">
                        Mailchimp Import Report - {{ mailchimpReportData?.location?.name || '' }}
                    </h3>
                    <button @click="showMailchimpReportModal = false" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>

                <div v-if="isLoadingMailchimpReport" class="text-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-500"></i>
                    <p class="mt-2 text-gray-600">Loading report...</p>
                </div>

                <div v-else-if="mailchimpReportData" class="space-y-6">
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-blue-800 mb-1">Total Records Logged</h4>
                            <p class="text-2xl font-bold text-blue-600">
                                {{ mailchimpReportData.summary.total_imports }}
                            </p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-green-800 mb-1">Successful Imports</h4>
                            <p class="text-2xl font-bold text-green-600">
                                {{ mailchimpReportData.summary.successful_imports }}
                            </p>
                        </div>
                        <div class="bg-red-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-red-800 mb-1">Failed Imports</h4>
                            <p class="text-2xl font-bold text-red-600">
                                {{ mailchimpReportData.summary.failed_imports }}
                            </p>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-purple-800 mb-1">Unique Emails Imported</h4>
                            <p class="text-2xl font-bold text-purple-600">
                                {{ mailchimpReportData.summary.unique_emails_imported }}
                            </p>
                        </div>
                    </div>

                    <!-- Last Import & Copy Separated Data -->
                    <div class="bg-gray-50 p-4 rounded-lg space-y-3 text-sm">
                        <div>
                            <span class="font-medium text-gray-700">Last Import:</span>
                            <span class="ml-1 text-gray-800">
                                {{ mailchimpReportData.summary.last_import_at || 'N/A' }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="text-gray-600">
                                Generate spreadsheet-ready separated data for this location.
                            </div>
                            <button
                                type="button"
                                @click="copyMailchimpSeparatedData"
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm flex items-center gap-2"
                                :disabled="isGeneratingMailchimpCopyData"
                            >
                                <i class="fa-solid fa-clipboard-list"></i>
                                <span v-if="!isGeneratingMailchimpCopyData">Copy Separated Data</span>
                                <span v-else>Generating...</span>
                            </button>
                        </div>
                    </div>

                    <!-- Error Details -->
                    <div>
                        <h4 class="text-md font-semibold mb-2 text-red-800">
                            Import Errors ({{ mailchimpReportData.errors.length }})
                        </h4>
                        <div v-if="mailchimpReportData.errors.length" class="bg-white border rounded-lg overflow-hidden">
                            <div class="max-h-64 overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="(row, index) in mailchimpReportData.errors" :key="index" class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-sm font-medium">
                                                {{ row.email || 'N/A' }}
                                            </td>
                                            <td class="px-4 py-2 text-sm text-red-700">
                                                {{ row.error || 'Unknown error' }}
                                            </td>
                                            <td class="px-4 py-2 text-sm text-gray-600">
                                                {{ row.date }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div v-else class="text-sm text-gray-500">
                            No failed imports recorded for this location.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Location Password Modal -->
        <div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalLabel" @hidden.bs.modal="closePasswordModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="passwordModalLabel">Location Password - {{ selectedLocation?.name }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="text" :value="selectedLocation?.password" class="form-control" readonly>
                                <button class="btn btn-outline-secondary" @click="copyPassword(selectedLocation, -1)">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="text" v-model="newPassword" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="text" v-model="confirmPassword" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="generatePassword(false)">
                            <i class="fa-solid fa-random"></i> Generate Password
                        </button>
                        <button type="button" class="btn btn-primary" @click="updatePassword">Save Changes</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Event Password Modal -->
        <!-- <div v-if="showEventPasswordModal" class="modal fade show" style="display: block;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Event Password - {{ event.event_name }}</h5>
                        <button type="button" class="btn-close" @click="showEventPasswordModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="text" :value="event.password" class="form-control" readonly>
                                <button class="btn btn-outline-secondary" @click="copyEventPassword">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="text" v-model="eventNewPassword" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="text" v-model="eventConfirmPassword" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="generatePassword(true)">
                            <i class="fa-solid fa-random"></i> Generate Password
                        </button>
                        <button type="button" class="btn btn-primary" @click="updateEventPassword">Save Changes</button>
                    </div>
                </div>
            </div>
        </div> -->

        <!-- Add/Edit Location Modal -->
        <div class="modal fade" id="createLocationModal" tabindex="-1" aria-labelledby="createLocationModalLabel" @hidden.bs.modal="closeLocationModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createLocationModalLabel">{{ isEditing ? 'Edit Location' : 'Create New Location' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form @submit.prevent="saveLocation">
                            <div class="mb-3">
                                <label class="form-label">Location Name</label>
                                <input 
                                    v-model="locationForm.name" 
                                    type="text" 
                                    class="form-control" 
                                    required 
                                />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Country</label>
                                <select 
                                    v-model="locationForm.country" 
                                    class="form-control" 
                                    required
                                >
                                    <option value="">Select Country</option>
                                    <option value="Australia">Australia</option>
                                    <option value="New Zealand">New Zealand</option>
                                    <option value="Canada">Canada</option>
                                    <option value="USA">USA</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">State (Optional)</label>
                                <input 
                                    v-model="locationForm.state" 
                                    type="text" 
                                    class="form-control" 
                                    placeholder="e.g., MT, VIC"
                                    maxlength="3"
                                    @input="locationForm.state = $event.target.value.toUpperCase()"
                                    style="text-transform: uppercase;"
                                />
                                <small class="text-muted">2-3 letter state abbreviation (e.g., MT, VIC, NSW)</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Category</label>
                                <select 
                                    v-model="locationForm.category" 
                                    class="form-control" 
                                    required
                                >
                                    <option value="">Select Category</option>
                                    <option value="Theatrical">Theatrical</option>
                                    <option value="AE Tour Stop">AE Tour Stop</option>
                                    <option value="Host a Show">Host a Show</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Date</label>
                                <input 
                                    v-model="locationForm.date" 
                                    type="date" 
                                    class="form-control" 
                                    :disabled="noDateYet"
                                    :required="!noDateYet"
                                />
                                <div class="form-check mt-2">
                                    <input 
                                        type="checkbox" 
                                        v-model="noDateYet" 
                                        class="form-check-input" 
                                        id="noDateYet"
                                    />
                                    <label class="form-check-label" for="noDateYet">
                                        No Date yet
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Time</label>
                                <input 
                                    v-model="locationForm.time" 
                                    type="time" 
                                    class="form-control" 
                                    :disabled="noTimeYet"
                                    :required="!noTimeYet"
                                />
                                <div class="form-check mt-2">
                                    <input 
                                        type="checkbox" 
                                        v-model="noTimeYet" 
                                        class="form-check-input" 
                                        id="noTimeYet"
                                    />
                                    <label class="form-check-label" for="noTimeYet">
                                        No Time yet
                                    </label>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">{{ isEditing ? 'Update Location' : 'Save Location' }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Update All Passwords Modal -->
        <div class="modal fade" id="allPasswordsModal" tabindex="-1" aria-labelledby="allPasswordsModalLabel" @hidden.bs.modal="closeAllPasswordsModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="allPasswordsModalLabel">Update All Location Passwords</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="text" v-model="allLocationsNewPassword" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="text" v-model="allLocationsConfirmPassword" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="generatePassword(false)">
                            <i class="fa-solid fa-random"></i> Generate Password
                        </button>
                        <button type="button" class="btn btn-primary" @click="updateAllPasswords">Update All Passwords</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mailchimp Import Modal -->
        <div v-if="showMailchimpModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Import to Mailchimp</h3>
                    <button @click="showMailchimpModal = false" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <p class="text-gray-600">
                        Import subscribers from <strong>{{ selectedLocation?.name }}</strong> to Mailchimp
                    </p>

                    <!-- Field Mapping Information -->
                    <div class="mb-4 p-4 bg-gray-50 rounded-lg">
                        <h4 class="font-semibold mb-2">Field Mappings:</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                            <div><strong>First Name:</strong> FNAME | MERGE1</div>
                            <div><strong>Last Name:</strong> LNAME | MERGE2</div>
                            <div><strong>Email Address:</strong> EMAIL | MERGE0</div>
                            <div><strong>Street Address:</strong> MMERGE10 | MERGE10</div>
                            <div><strong>City:</strong> CITY | MERGE3</div>
                            <div><strong>State:</strong> STATE | MERGE6</div>
                            <div><strong>Zip Code:</strong> ZIPCODE | MERGE7</div>
                            <div><strong>Country:</strong> COUNTRY | MERGE8</div>
                            <div><strong>Mobile Number:</strong> PHONE | MERGE4</div>
                            <div><strong>SMS Phone:</strong> SMSPHONE | MERGE30</div>
                            <div><strong>Age:</strong> MMERGE14 | MERGE14</div>
                            <div><strong>Gender:</strong> GENDER | MERGE17</div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Select Mailchimp Audience
                        </label>
                        <select 
                            v-model="selectedList"
                            class="w-full border rounded px-3 py-2"
                            :disabled="isImporting"
                        >
                            <option value="">Select an audience...</option>
                            <option 
                                v-for="list in mailchimpLists" 
                                :key="list.id" 
                                :value="list.id"
                            >
                                {{ list.name }} ({{ list.stats.member_count }} members)
                            </option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Add Tags (comma-separated)
                        </label>
                        <input 
                            type="text" 
                            v-model="tags"
                            class="w-full border rounded px-3 py-2"
                            placeholder="e.g., 2025, FILM TOUR - WARREN MILLER, SHOW - MELBOURNE"
                            :disabled="isImporting"
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Enter tags separated by commas. Each tag will be added to the subscribers.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button 
                            @click="showMailchimpModal = false"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                            :disabled="isImporting"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="handleMailchimpImport"
                            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                            :disabled="isImporting || !selectedList"
                        >
                            <span v-if="isImporting">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                                Importing...
                            </span>
                            <span v-else>
                                Import to Mailchimp
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mailchimp Settings Modal -->
        <div class="modal fade" :class="{ 'show': showMailchimpSettingsModal }" :style="{ display: showMailchimpSettingsModal ? 'block' : 'none' }" id="mailchimpSettingsModal" tabindex="-1" aria-labelledby="mailchimpSettingsModalLabel" aria-modal="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="mailchimpSettingsModalLabel">Mailchimp Auto-Sync Settings</h5>
                        <button type="button" class="btn-close" @click="showMailchimpSettingsModal = false" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-4">
                            <label class="form-label">Mailchimp Account</label>
                            <select 
                                v-model="mailchimpSettings.mailchimp_account"
                                class="form-select"
                                @change="loadListsForAccount(mailchimpSettings.mailchimp_account)"
                            >
                                <option 
                                    v-for="(account, key) in availableAccounts" 
                                    :key="key" 
                                    :value="key"
                                    :disabled="!account.enabled"
                                >
                                    {{ account.name }} {{ !account.enabled ? '(Not Configured)' : '' }}
                                </option>
                            </select>
                            <div class="form-text">
                                Select which Mailchimp account to use for auto-sync.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Auto-Sync</label>
                            <div class="form-check">
                                <input 
                                    type="checkbox" 
                                    v-model="mailchimpSettings.auto_sync"
                                    class="form-check-input"
                                    id="autoSyncCheck"
                                >
                                <label class="form-check-label" for="autoSyncCheck">Enable automatic synchronization</label>
                            </div>
                        </div>

                        <div class="mb-4" v-if="mailchimpSettings.auto_sync">
                            <label class="form-label">Default Mailchimp Audience</label>
                            <select 
                                v-model="mailchimpSettings.default_list_id"
                                class="form-select"
                                :class="{ 'is-invalid': !mailchimpSettings.default_list_id && mailchimpSettings.auto_sync }"
                            >
                                <option value="">Select an audience...</option>
                                <option 
                                    v-for="list in availableLists" 
                                    :key="list.id" 
                                    :value="list.id"
                                >
                                    {{ list.name }} ({{ list.stats.member_count }} members)
                                </option>
                            </select>
                            <div v-if="availableLists.length === 0" class="form-text text-warning">
                                <i class="fa-solid fa-exclamation-triangle me-1"></i>
                                No audiences found for this account. Please check your API configuration or select a different account.
                            </div>
                            <div v-if="!mailchimpSettings.default_list_id && mailchimpSettings.auto_sync" class="invalid-feedback">
                                Please select a Mailchimp audience when auto-sync is enabled.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Film Tour Code</label>
                            <input 
                                type="text" 
                                v-model="mailchimpSettings.film_tour"
                                class="form-control"
                                placeholder="e.g., WM, RUNNATION, etc."
                                required
                            >
                            <div class="form-text">
                                Enter the film tour code (e.g., WM for Warren Miller, RUNNATION). This will be used in the SOURCE tag.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Default Tags</label>
                            <input 
                                type="text" 
                                v-model="mailchimpSettings.default_tags"
                                class="form-control"
                                placeholder="e.g., 2025, FILM TOUR - WARREN MILLER, SHOW - MELBOURNE"
                            >
                            <div class="form-text">
                                Enter tags separated by commas. Each tag will be added to the subscribers.
                            </div>
                        </div>

                        <!-- Tag Preview Section -->
                        <!-- <div class="mb-4" v-if="mailchimpSettings.film_tour">
                            <label class="form-label">Tag Preview</label>
                            <div class="p-3 bg-light rounded">
                                <div class="mb-2">
                                    <strong>Sample tags that will be automatically generated:</strong>
                                </div>
                                <div class="row">
                                    <div class="col-md-6" v-for="(preview, index) in tagPreview" :key="index">
                                        <div class="mb-3 p-2 border rounded">
                                            <div class="fw-bold text-primary mb-1">{{ preview.location }}</div>
                                            <div class="mb-1">
                                                <span class="badge bg-success me-1">{{ preview.showTag }}</span>
                                            </div>
                                            <div class="mb-1">
                                                <span class="badge bg-info">{{ preview.sourceTag }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-muted small">
                                    <i class="fa-solid fa-info-circle me-1"></i>
                                    These tags will be automatically added to subscribers based on their selected location.
                                </div>
                            </div>
                        </div> -->
                    </div>
                    <div class="modal-footer">
                        <button 
                            type="button"
                            class="btn btn-secondary" 
                            @click="showMailchimpSettingsModal = false"
                        >
                            Cancel
                        </button>
                        <button 
                            type="button"
                            class="btn btn-primary"
                            @click="saveMailchimpSettings"
                        >
                            Save Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="showMailchimpSettingsModal" class="modal-backdrop fade show"></div>

        <!-- Eventbrite Import Modal -->
        <div v-if="showEventbriteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Import Attendees from Eventbrite</h3>
                    <button @click="closeEventbriteModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <p class="text-gray-600">
                        Import attendees from Eventbrite for <strong>{{ selectedLocation?.name }}</strong>
                    </p>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Eventbrite Event Link
                        </label>
                        <input 
                            type="text" 
                            v-model="eventbriteLink"
                            class="w-full border rounded px-3 py-2"
                            placeholder="https://www.eventbrite.com/e/event-name-tickets-1234567890"
                            :disabled="isFetchingEventbrite"
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Paste the Eventbrite event URL. The system will extract the event ID and fetch all attendees.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3 mb-4">
                        <button 
                            @click="closeEventbriteModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                            :disabled="isFetchingEventbrite"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="fetchEventbriteAttendees"
                            class="px-4 py-2 bg-orange-500 text-white rounded hover:bg-orange-600"
                            :disabled="isFetchingEventbrite || !eventbriteLink.trim()"
                        >
                            <span v-if="isFetchingEventbrite">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                                Fetching...
                            </span>
                            <span v-else>
                                <i class="fa-solid fa-download mr-2"></i>
                                Fetch Attendees
                            </span>
                        </button>
                    </div>

                    <!-- Attendees List -->
                    <div v-if="eventbriteAttendees.length > 0" class="mt-6">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-md font-semibold">
                                Found {{ eventbriteAttendees.length }} unique attendee(s)
                            </h4>
                            <div class="flex gap-2">
                                <button 
                                    @click="exportEventbriteAttendees"
                                    class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600"
                                    :disabled="isImportingEventbrite"
                                >
                                    <i class="fa-solid fa-file-export mr-2"></i>
                                    Export CSV
                                </button>
                                <button 
                                    @click="openEventbriteMailchimpModal"
                                    class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                                    :disabled="isImportingEventbrite"
                                >
                                    <i class="fa-solid fa-upload mr-2"></i>
                                    Import to Mailchimp
                                </button>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">First Name</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Last Name</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">City</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Country</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="(attendee, index) in eventbriteAttendees" :key="index" class="hover:bg-gray-50">
                                        <td class="px-4 py-2 text-sm">{{ attendee.email || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.first_name || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.last_name || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.phone || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.city || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.state || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.country || '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Eventbrite Mailchimp Import Modal -->
        <div v-if="showEventbriteMailchimpModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Import Eventbrite Attendees to Mailchimp</h3>
                    <button @click="closeEventbriteMailchimpModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <p class="text-gray-600">
                        Import <strong>{{ eventbriteAttendees.length }}</strong> attendees from Eventbrite for <strong>{{ selectedLocation?.name }}</strong>
                    </p>

                    <!-- Mailchimp Account Selection -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Mailchimp Account
                        </label>
                        <select 
                            v-model="eventbriteMailchimpAccount"
                            @change="loadEventbriteLists(eventbriteMailchimpAccount)"
                            class="w-full border rounded px-3 py-2"
                            :disabled="isLoadingEventbriteLists"
                        >
                            <option value="">Select an account...</option>
                            <option 
                                v-for="(account, key) in eventbriteAvailableAccounts" 
                                :key="key" 
                                :value="key"
                                :disabled="!account.enabled"
                            >
                                {{ account.name }} {{ !account.enabled ? '(Not Configured)' : '' }}
                            </option>
                        </select>
                    </div>

                    <!-- Mailchimp Audience Selection -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Mailchimp Audience
                        </label>
                        <select 
                            v-model="eventbriteSelectedList"
                            class="w-full border rounded px-3 py-2"
                            :disabled="isLoadingEventbriteLists || !eventbriteMailchimpAccount"
                        >
                            <option value="">Select an audience...</option>
                            <option 
                                v-for="list in eventbriteMailchimpLists" 
                                :key="list.id" 
                                :value="list.id"
                            >
                                {{ list.name }} ({{ list.stats.member_count }} members)
                            </option>
                        </select>
                        <div v-if="isLoadingEventbriteLists" class="mt-2 text-sm text-gray-500">
                            <i class="fa-solid fa-spinner fa-spin mr-1"></i>
                            Loading audiences...
                        </div>
                    </div>

                    <!-- Preview of Columns to Import -->
                    <div v-if="eventbriteAttendees.length > 0" class="mb-4">
                        <h4 class="text-md font-semibold mb-2">Preview - Columns to Import</h4>
                        <div class="bg-gray-50 p-4 rounded-lg mb-3">
                            <p class="text-sm text-gray-600 mb-2">The following columns will be imported:</p>
                            <div class="flex flex-wrap gap-2">
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">Email</span>
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">First Name</span>
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">Last Name</span>
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">Phone Number</span>
                            </div>
                        </div>
                        
                        <div class="bg-white border rounded-lg overflow-hidden">
                            <div class="max-h-64 overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">First Name</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Last Name</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="(attendee, index) in eventbriteAttendees.slice(0, 10)" :key="index" class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-sm">{{ attendee.email || '-' }}</td>
                                            <td class="px-4 py-2 text-sm">{{ attendee.first_name || '-' }}</td>
                                            <td class="px-4 py-2 text-sm">{{ attendee.last_name || '-' }}</td>
                                            <td class="px-4 py-2 text-sm">{{ attendee.phone || '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="eventbriteAttendees.length > 10" class="px-4 py-2 bg-gray-50 text-sm text-gray-600 border-t">
                                Showing first 10 of {{ eventbriteAttendees.length }} attendees
                            </div>
                        </div>
                    </div>

                    <!-- Tags Section -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Tags to Apply
                        </label>
                        <div class="bg-gray-50 p-4 rounded-lg mb-3">
                            <p class="text-sm text-gray-600 mb-2">Default tags (editable):</p>
                            <div class="flex flex-wrap gap-2 mb-3">
                                <span 
                                    v-for="(tag, index) in eventbriteDefaultTags" 
                                    :key="index"
                                    class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium"
                                >
                                    {{ tag }}
                                </span>
                            </div>
                        </div>
                        <input 
                            type="text" 
                            v-model="eventbriteTags"
                            class="w-full border rounded px-3 py-2"
                            placeholder="SHOW - LOCATION, SOURCE - WM LOCATION COMP 2025, EVENTBRITE, ..."
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Edit tags separated by commas. Default tags are pre-filled but you can add or modify them.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button 
                            @click="closeEventbriteMailchimpModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                            :disabled="isImportingEventbrite"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="importEventbriteToMailchimp"
                            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                            :disabled="isImportingEventbrite || !eventbriteSelectedList || !eventbriteMailchimpAccount"
                        >
                            <span v-if="isImportingEventbrite">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                                Importing...
                            </span>
                            <span v-else>
                                <i class="fa-solid fa-upload mr-2"></i>
                                Import to Mailchimp
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ticket Report Modal -->
        <div v-if="showTicketReportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Ticket Report - {{ selectedLocation?.name }}</h3>
                    <button @click="closeTicketReportModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div v-if="isLoadingReport" class="text-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-500"></i>
                    <p class="mt-2 text-gray-600">Loading report...</p>
                </div>
                
                <div v-else-if="ticketReportData" class="space-y-6">
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-5 gap-4">
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-blue-800 mb-1">Ticket Emails</h4>
                            <p class="text-2xl font-bold text-blue-600">{{ ticketReportData.summary.ticket_emails_count }}</p>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-purple-800 mb-1">Win Form Emails</h4>
                            <p class="text-2xl font-bold text-purple-600">{{ ticketReportData.summary.signup_emails_count }}</p>
                        </div>
                        <div class="bg-orange-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-orange-800 mb-1">Duplicates</h4>
                            <p class="text-2xl font-bold text-orange-600">{{ ticketReportData.summary.duplicate_emails_count }}</p>
                            <p class="text-xs text-orange-600 mt-1">In both sources</p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-green-800 mb-1">Ticket Only</h4>
                            <p class="text-2xl font-bold text-green-600">{{ ticketReportData.summary.ticket_only_count }}</p>
                        </div>
                        <div class="bg-yellow-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-yellow-800 mb-1">Sign-Up Only</h4>
                            <p class="text-2xl font-bold text-yellow-600">{{ ticketReportData.summary.signup_only_count }}</p>
                        </div>
                    </div>

                    <!-- TIX Copy Separated Data -->
                    <div class="bg-gray-50 p-4 rounded-lg space-y-3 text-sm">
                        <div>
                            <span class="font-medium text-gray-700">TIX Spreadsheet Line:</span>
                            <span class="ml-1 text-gray-800">
                                Generate spreadsheet-ready separated data for this ticket (TIX) report.
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="text-gray-600">
                                This uses TIX tags (e.g. <code>SOURCE - FILM LOCATION TIX {{ new Date().getFullYear() }}</code>) for your ticket analytics row.
                            </div>
                            <button
                                type="button"
                                @click="copyTicketSeparatedData"
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm flex items-center gap-2"
                                :disabled="isGeneratingTicketCopyData"
                            >
                                <i class="fa-solid fa-clipboard-list"></i>
                                <span v-if="!isGeneratingTicketCopyData">Copy TIX Separated Data</span>
                                <span v-else>Generating...</span>
                            </button>
                        </div>
                    </div>

                    <!-- Duplicate Emails Section (emails in both ticket and sign-up) -->
                    <div v-if="ticketReportData.duplicate_emails && ticketReportData.duplicate_emails.length > 0">
                        <h4 class="text-md font-semibold mb-3 text-orange-800">
                            Duplicate Emails - Found in Both Ticket & Sign-Up ({{ ticketReportData.duplicate_emails.length }})
                        </h4>
                        <div class="bg-white border rounded-lg overflow-hidden">
                            <div class="max-h-96 overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ticket Data</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Sign-Up Data</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="(duplicate, index) in ticketReportData.duplicate_emails" :key="index" class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-sm font-medium">{{ duplicate.email }}</td>
                                            <td class="px-4 py-2 text-sm">
                                                <div v-if="duplicate.ticket_data" class="bg-blue-50 p-2 rounded">
                                                    <div class="font-semibold text-blue-800">{{ duplicate.ticket_data.first_name }} {{ duplicate.ticket_data.last_name }}</div>
                                                    <div class="text-xs text-blue-600">{{ duplicate.ticket_data.phone || 'No phone' }}</div>
                                                    <div class="text-xs text-blue-500 mt-1">{{ duplicate.ticket_data.source }}</div>
                                                </div>
                                                <div v-else class="text-gray-400">No ticket data</div>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <div v-if="duplicate.signup_data" class="bg-purple-50 p-2 rounded">
                                                    <div class="font-semibold text-purple-800">{{ duplicate.signup_data.first_name }} {{ duplicate.signup_data.last_name }}</div>
                                                    <div class="text-xs text-purple-600">{{ duplicate.signup_data.phone || 'No phone' }}</div>
                                                    <div class="text-xs text-purple-500 mt-1">{{ duplicate.signup_data.source }}</div>
                                                </div>
                                                <div v-else class="text-gray-400">No sign-up data</div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-4 text-gray-500">
                        <i class="fa-solid fa-check-circle text-green-500 text-2xl mb-2"></i>
                        <p>No duplicate emails found between ticket and sign-up data!</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
