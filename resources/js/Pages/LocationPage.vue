<script setup>
import { ref, computed, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';

// ✅ Define Props to Receive Locations from Backend
const props = defineProps({
    event: Object,
    locations: Array
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
const newPassword = ref('');
const confirmPassword = ref('');
const eventNewPassword = ref('');
const eventConfirmPassword = ref('');
const allLocationsNewPassword = ref('');
const allLocationsConfirmPassword = ref('');
const showCSVFormat = ref(false);
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
    film_tour: 'WM'  // Default to WM, can be changed in settings
});
const availableLists = ref([]);
const isSettingsLoading = ref(false);

// Add new form for location creation
const locationForm = useForm({
    id: null,
    name: '',
    event_id: '',
    date: '',
    time: ''
});

const formatLocationDateTime = (date, time) => {
    if (!date || !time) return '';

    const datetime = new Date(`${date}T${time}`);
    return datetime.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
};

const generateLocationTags = (locationName) => {
    const tags = [];
    const filmTour = mailchimpSettings.value.film_tour || 'WM';
    const year = new Date().getFullYear() + 1; // Use next year by default
    
    // Add SHOW tag
    tags.push(`SHOW - ${locationName.toUpperCase()}`);
    
    // Add SOURCE tag with configured film tour code
    tags.push(`SOURCE - ${filmTour} ${locationName.toUpperCase()} COMP ${year}`);
    
    return tags;
};

const importDataToMailChimp = async (location) => {
    selectedLocation.value = location;
    showMailchimpModal.value = true;
    
    try {
        const response = await axios.get(route('location.mailchimpLists'));
        mailchimpLists.value = response.data.lists;
        
        // Auto-generate tags based on location
        const locationTags = generateLocationTags(location.name);
        tags.value = locationTags.join(', ');
    } catch (error) {
        console.error('Failed to fetch Mailchimp lists:', error);
        Swal.fire('Error!', 'Failed to fetch Mailchimp lists. Please try again.', 'error');
    }
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
        const filmTour = mailchimpSettings.value.film_tour || 'WM';
        const year = new Date().getFullYear() + 1;
        const locationName = selectedLocation.value.name;
        const locationFirstWord = locationName.split(' ')[0].toUpperCase(); // Get first word only
        
        // Create the SOURCE tag in the exact format
        const sourceTag = `SOURCE - ${filmTour} ${locationFirstWord} COMP ${year}`;
        const showTag = `SHOW - ${locationName.toUpperCase()}`; // Keep full name for SHOW tag
        
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
                    tags: allTags
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
                errors.push(`Chunk ${i + 1} failed: ${error.message}`);
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
            html: `Successfully imported ${successCount} out of ${totalSubscribers} subscribers.<br>Failed: ${failureCount}${errorHtml}<br><br>A log file has been downloaded with complete details.`,
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

// Add CSV import functionality
const handleFileUpload = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    // Check file type
    if (!file.name.endsWith('.csv')) {
        Swal.fire('Error!', 'Please upload a CSV file.', 'error');
        return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const text = e.target.result;
            parseCSV(text);
        } catch (error) {
            Swal.fire('Error!', 'Failed to read the CSV file. Please check the file format.', 'error');
        }
    };
    reader.onerror = () => {
        Swal.fire('Error!', 'Failed to read the file.', 'error');
    };
    reader.readAsText(file);
};

const parseCSV = (csvText) => {
    // Split by newlines and handle different line endings, then filter out empty rows
    const rows = csvText
        .split(/\r?\n/)
        .map(row => row.split(','))
        .filter(row => row.some(cell => cell !== ''));

    console.log('Total rows found:', rows.length - 1); // -1 for header row

    if (rows.length < 2) {
        Swal.fire('Error!', 'CSV file is empty or has no data rows.', 'error');
        return;
    }

    // Process data rows
    const validLocations = [];
    const errors = [];

    // Function to convert 12-hour time to 24-hour format
    const convertTo24Hour = (timeStr) => {
        if (!timeStr) return '';
        
        const [time, period] = timeStr.toLowerCase().split(' ');
        if (!time || !period) return '';

        let [hours, minutes] = time.split(':');
        if (!hours || !minutes) return '';

        hours = parseInt(hours);
        if (isNaN(hours)) return '';

        if (period === 'pm' && hours !== 12) {
            hours += 12;
        } else if (period === 'am' && hours === 12) {
            hours = 0;
        }

        return `${String(hours).padStart(2, '0')}:${minutes}`;
    };

    rows.slice(1).forEach((row, index) => {
        // Skip empty rows or rows with all empty cells
        if (!row.some(cell => cell !== '')) {
            console.log(`Skipping empty row ${index + 2}`);
            return;
        }

        try {
            // Extract and clean the raw data
            let name = row[0].trim();
            let dateTimeParts = row.slice(1).join(',').split(',').map(part => part.trim());

            console.log(`Processing row ${index + 2}:`, {
                name,
                dateTimeParts
            });

            // Find the time part (should contain "pm" or "am")
            let timeStr = dateTimeParts.find(part => 
                part.toLowerCase().includes('pm') || 
                part.toLowerCase().includes('am')
            );
            
            // The remaining parts should form the date
            let dateParts = dateTimeParts.filter(part => part !== timeStr);
            let fullDateStr = dateParts.join(' ').trim();

            // Convert time to 24-hour format
            let formattedTime = convertTo24Hour(timeStr);
            if (!formattedTime) {
                throw new Error(`Invalid time format: ${timeStr}. Expected format: "H:MM am/pm"`);
            }

            // Parse the date
            const date = new Date(fullDateStr);
            
            // Validate the date
            if (isNaN(date.getTime())) {
                throw new Error(`Could not parse date: ${fullDateStr}`);
            }

            // Format the date in MySQL format (YYYY-MM-DD)
            const formattedYear = date.getFullYear();
            const formattedMonth = String(date.getMonth() + 1).padStart(2, '0');
            const formattedDay = String(date.getDate()).padStart(2, '0');
            const formattedDate = `${formattedYear}-${formattedMonth}-${formattedDay}`;

            const locationData = {
                name: name,
                date: formattedDate,
                time: formattedTime,
                event_id: props.event.id
            };

            // Validate data
            if (!locationData.name || !locationData.date || !locationData.time || 
                locationData.name.trim() === '' || 
                locationData.date.trim() === '' || 
                locationData.time.trim() === '') {
                throw new Error('Missing required data');
            }

            validLocations.push(locationData);
            console.log(`Successfully processed row ${index + 2}:`, locationData);

        } catch (error) {
            console.error(`Error processing row ${index + 2}:`, error.message);
            console.error('Row data:', row);
            errors.push(`Row ${index + 2}: ${error.message}`);
        }
    });

    console.log('Total valid locations:', validLocations.length);
    console.log('Total errors:', errors.length);

    if (errors.length > 0) {
        console.log('Errors found:', errors);
        Swal.fire({
            title: 'Import Errors',
            html: `Found ${errors.length} errors:<br>${errors.join('<br>')}`,
            icon: 'warning',
            confirmButtonText: 'OK'
        });
        return;
    }

    if (validLocations.length === 0) {
        Swal.fire('Error!', 'No valid locations found to import.', 'error');
        return;
    }

    // Import locations in batches
    importLocations(validLocations);
};

const importLocations = async (locations) => {
    try {
        console.log('Starting import of', locations.length, 'locations');
        
        const results = {
            success: [],
            failed: []
        };

        // Import locations sequentially to avoid overwhelming the server
        for (let i = 0; i < locations.length; i++) {
            const location = locations[i];
            try {
                console.log(`Importing location ${i + 1}/${locations.length}:`, location);
                const response = await router.post(route('location.store'), location);
                
                // Add to success list
                results.success.push({
                    index: i + 1,
                    name: location.name,
                    date: location.date,
                    time: location.time
                });

                // Log progress
                console.log(`Successfully imported ${i + 1}/${locations.length}:`, {
                    name: location.name,
                    date: location.date,
                    time: location.time
                });

            } catch (error) {
                console.error(`Failed to import location ${i + 1}/${locations.length}:`, location, error);
                results.failed.push({
                    index: i + 1,
                    name: location.name,
                    error: error.message
                });
            }

            // Add a small delay between imports to prevent overwhelming the server
            await new Promise(resolve => setTimeout(resolve, 100));
        }

        console.log('Import completed. Results:', {
            total: locations.length,
            successful: results.success.length,
            failed: results.failed.length,
            successList: results.success,
            failedList: results.failed
        });

        if (results.failed.length > 0) {
            // Show error message with details
            const errorMessage = `
                Imported ${results.success.length} of ${locations.length} locations.<br><br>
                Failed to import ${results.failed.length} locations:<br>
                ${results.failed.map(f => `Row ${f.index}: ${f.name}`).join('<br>')}
            `;
            
            Swal.fire({
                title: 'Partial Import Success',
                html: errorMessage,
                icon: 'warning',
                confirmButtonText: 'OK'
            }).then(() => {
                router.reload();
            });
        } else {
            // All successful
            Swal.fire({
                title: 'Success!',
                html: `Successfully imported all ${locations.length} locations.<br><br>
                      Imported locations:<br>
                      ${results.success.map(s => `${s.name} (${s.date})`).join('<br>')}`,
                icon: 'success',
                confirmButtonText: 'OK'
            }).then(() => {
                router.reload();
            });
        }
    } catch (error) {
        console.error('Import error:', error);
        Swal.fire({
            title: 'Error!',
            text: 'Failed to complete the import process. Please check the console for details.',
            icon: 'error',
            confirmButtonText: 'OK'
        });
    }
};

// ✅ Computed Property to Filter Locations
const filteredLocations = computed(() => {
    if (!searchQuery.value) {
        return props.locations;
    }
    return props.locations.filter(location =>
        location.name.toLowerCase().includes(searchQuery.value.toLowerCase())
    );
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
    router.get(route('pickawinner.alllocation', { event: props.event.id }));
};


// Function to open location creation modal
const openLocationModal = (location = null) => {
    locationForm.reset();
    if (location) {
        isEditing.value = true;
        locationForm.id = location.id;
        locationForm.name = location.name;
        locationForm.date = location.date;
        locationForm.time = location.time;
        locationForm.event_id = props.event.id;
    } else {
        isEditing.value = false;
        locationForm.event_id = props.event.id;
    }
    showLocationModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('createLocationModal'));
    modalElement.show();
};

// Function to save location
const saveLocation = () => {
    if (!locationForm.name || !locationForm.date || !locationForm.time) {
        Swal.fire('Error', 'Location name, date and time are required!', 'error');
        return;
    }

    if (isEditing.value) {
        router.put(route('location.update', locationForm.id), locationForm, {
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
        router.post(route('location.store'), locationForm, {
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

// Function to delete location
const deleteLocation = (location) => {
    Swal.fire({
        title: 'Are you sure?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('location.destroy', location.id), {
                onSuccess: () => {
                    Swal.fire(
                        'Deleted!',
                        'Location has been deleted.',
                        'success'
                    );
                    router.reload();
                },
                onError: () => {
                    Swal.fire(
                        'Error!',
                        'There was an issue deleting the location.',
                        'error'
                    );
                }
            });
        }
    });
};

// Add function to handle modal close
const closeLocationModal = () => {
    showLocationModal.value = false;
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
        const response = await axios.get(route('mailchimp.autosync.settings'));
        const { settings, available_lists } = response.data;
        
        mailchimpSettings.value = {
            ...settings,
            film_tour: settings.film_tour || 'WM',
            default_tags: Array.isArray(settings.default_tags) ? settings.default_tags.join(', ') : ''
        };
        availableLists.value = available_lists;
        showMailchimpSettingsModal.value = true;
    } catch (error) {
        console.error('Failed to fetch Mailchimp settings:', error);
        Swal.fire('Error', 'Failed to load Mailchimp settings', 'error');
    } finally {
        isSettingsLoading.value = false;
    }
};

const saveMailchimpSettings = async () => {
    try {
        const settings = {
            ...mailchimpSettings.value,
            film_tour: mailchimpSettings.value.film_tour || 'WM',
            default_tags: mailchimpSettings.value.default_tags.split(',').map(tag => tag.trim()).filter(tag => tag),
            enabled_locations: Array.isArray(mailchimpSettings.value.enabled_locations) 
                ? mailchimpSettings.value.enabled_locations 
                : []
        };

        console.log('Saving settings:', settings); // Debug log

        await axios.post(route('mailchimp.autosync.update'), settings);
        
        // Close modal by updating the reactive state
        showMailchimpSettingsModal.value = false;
        
        Swal.fire('Success', 'Mailchimp auto-sync settings updated successfully', 'success');
    } catch (error) {
        console.error('Failed to save Mailchimp settings:', error);
        Swal.fire('Error', 'Failed to save Mailchimp settings', 'error');
    }
};

// Add the download function
const downloadMailchimpLogs = () => {
    window.location.href = route('location.downloadMailchimpLogs');
};

</script>

<template>
    <Head title="Pick a Winner Page" />

    <AuthenticatedLayout>
        <div class="p-4">
            <div class="mx-auto max-w-4xl">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center">
                        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 mb-4">
                            <h3 class="text-lg font-semibold text-center sm:text-left">Locations Password for {{ event.event_name }}</h3>
                            <div class="flex gap-2">
                                <!-- CSV Import Button with Help Text -->
                                <div class="relative group">
                                    <label style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;">
                                        <i class="fa-solid fa-file-import"></i>
                                        <input 
                                            type="file" 
                                            accept=".csv"
                                            class="hidden"
                                            @change="handleFileUpload"
                                        >
                                    </label>
                                </div>
                                <!-- Download Mailchimp Logs Button -->
                                <button 
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    @click="downloadMailchimpLogs"
                                    title="Download Mailchimp Import History"
                                >
                                    <i class="fa-solid fa-download"></i>
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
                                <!-- Add Location Button -->
                                <button 
                                    class="bg-black text-white px-4 py-2 rounded hover:bg-black-700"
                                    @click="openLocationModal()"
                                >
                                    <i class="fa-solid fa-plus"></i> Add Location
                                </button>
                            </div>
                        </div>
                        <!-- <div class="d-flex justify-content-between items-center mb-4">
                            <button class="btn btn-primary" @click="openEventPasswordModal">
                                <i class="fa-solid fa-key"></i> Event Password
                            </button>
                        </div> -->
                        <!-- ✅ Search Bar -->
                        <input 
                            v-model="searchQuery" 
                            type="text" 
                            placeholder="Search location..."
                            class="w-full border p-2 rounded mb-4 focus:ring focus:ring-blue-300"
                        />

                        <!-- ✅ Locations List -->
                        <div v-if="filteredLocations.length > 0" class="space-y-2">
                            <div 
                                v-for="(location, index) in filteredLocations" 
                                :key="index" 
                                class="flex items-center space-x-2 w-full"
                            >
                                <div class=" px-4 py-2 rounded w-full text-left truncate" 
                                style="background-color: white; border: 2px solid black; font-weight: bold; color: black; border-radius: 5px; padding: 10px 20px; cursor: pointer;">
                                    {{ location.name }} <br> {{ formatLocationDateTime(location.date, location.time) }}
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
                                <!-- Delete Button -->
                                <button 
                                    @click="deleteLocation(location)"
                                    class="text-white px-3 py-2 rounded"
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="Delete Location"
                                >
                                    <i class="fa-solid fa-trash"></i>
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
                            </div>
                        </div>

                        <!-- ✅ If No Locations Found -->
                        <div v-else>
                            <p class="text-gray-600 mt-3">No locations found.</p>
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
                                <label class="form-label">Date</label>
                                <input 
                                    v-model="locationForm.date" 
                                    type="date" 
                                    class="form-control" 
                                    required 
                                />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Time</label>
                                <input 
                                    v-model="locationForm.time" 
                                    type="time" 
                                    class="form-control" 
                                    required 
                                />
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

        <!-- CSV Format Modal -->
        <div v-if="showCSVFormat" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-2xl w-full mx-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">CSV Import Format Instructions</h3>
                    <button @click="showCSVFormat = false" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                <div class="space-y-4">
                    <p class="text-gray-600">Your CSV file should follow this format:</p>
                    <div class="bg-gray-100 p-4 rounded">
                        <pre class="text-sm">name,date,time
Adelaide,September 01 2024,4:00 pm
Melbourne,September 02 2024,6:00 pm</pre>
                    </div>
                    <div class="space-y-2">
                        <p class="font-semibold">Requirements:</p>
                        <ul class="list-disc list-inside space-y-1 text-gray-600">
                            <li>File must be in CSV format</li>
                            <li>Must include header row with columns: name, date, time</li>
                            <li>Date format: Month DD YYYY (e.g., "September 01 2024")</li>
                            <li>Time format: H:MM am/pm (e.g., "4:00 pm" or "10:30 am")</li>
                        </ul>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button 
                            @click="showCSVFormat = false"
                            class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-700"
                        >
                            Got it
                        </button>
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
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-2xl w-full mx-4">
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

                        <div class="mb-4">
                            <label class="form-label">Default Mailchimp Audience</label>
                            <select 
                                v-model="mailchimpSettings.default_list_id"
                                class="form-select"
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
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Film Tour Code</label>
                            <input 
                                type="text" 
                                v-model="mailchimpSettings.film_tour"
                                class="form-control"
                                placeholder="e.g., WM, BF, etc."
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
    </AuthenticatedLayout>
</template>
