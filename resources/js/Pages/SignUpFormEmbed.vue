<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';

const props = defineProps({
    form: Object,
    event: Object,
    locations: Array
});

const hasAddressFields = computed(() => {
    return JSON.parse(props.form.questions).some(question => 
        ['street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country'].includes(question.column_name)
    );
});

// Find the country question text dynamically (in case user renamed it)
const countryQuestionText = computed(() => {
    const questions = JSON.parse(props.form.questions || '[]');
    const countryQuestion = questions.find(q => q.column_name === 'country');
    return countryQuestion ? countryQuestion.text : 'Country';
});

// Find the mobile number question text dynamically (in case user renamed it)
const mobileNumberQuestionText = computed(() => {
    const questions = JSON.parse(props.form.questions || '[]');
    const mobileQuestion = questions.find(q => q.column_name === 'mobile_number');
    return mobileQuestion ? mobileQuestion.text : 'Mobile Number';
});

// Store form values
const formValues = ref({});
const isSubmitted = ref(false);
const otherValues = ref({});
const subscriptionStatus = ref(null); // null = not checked, 'subscribed', 'not_subscribed', 'checking'
const isSubscriptionChecked = ref(false);
const showResubscribePrompt = ref(false);
const isComplianceState = ref(false);
const complianceSignupUrl = ref(null);
const selectedLocation = ref('');
const isSubmitting = ref(false);
const selectedLocationData = ref({
    id: null,
    name: '',
    date: '',
    time: ''
});

// Get today's date
const today = new Date();

// Calculate date 1 week ago
const oneWeekAgo = new Date(today);
oneWeekAgo.setDate(today.getDate() - 7);

// Calculate date 3 weeks from today
const threeWeeksFromToday = new Date(today);
threeWeeksFromToday.setDate(today.getDate() + 21);

// Format locations with proper date and time formats
const formattedLocations = computed(() => {
    if (!props.locations || !Array.isArray(props.locations)) return [];
    
    return props.locations.map(location => {
        return {
            ...location,
            formatted_date: formatDate(location.date),
            formatted_time: formatTime(location.time),
            date_obj: new Date(location.date) // Add a proper Date object for easier comparison
        };
    });
});

// Initialize form values for multiple select
onMounted(() => {
    const questions = JSON.parse(props.form.questions);
    questions.forEach(question => {
        if (question.allowMultiple) {
            formValues.value[question.text] = [];
        }
    });
});

// Update checkForOther function to handle multiple selections
const checkForOther = (question) => {
    if (!question.allowMultiple) {
        if (formValues.value[question.text] !== 'Other') {
            otherValues.value[question.column_name] = '';
        }
    } else {
        if (!formValues.value[question.text]?.includes('Other')) {
            otherValues.value[question.column_name] = '';
        }
    }
};

// When event.show_all_locations is true, show all locations; otherwise filter to 4-week window (1 week ago → 3 weeks from now)
const fourWeekWindowLocations = computed(() => {
    if (props.event?.show_all_locations) {
        return formattedLocations.value;
    }
    return formattedLocations.value.filter(location => {
        return location.date_obj >= oneWeekAgo && location.date_obj <= threeWeeksFromToday;
    });
});

// Group locations by date
const groupedLocations = computed(() => {
    if (!fourWeekWindowLocations.value || !Array.isArray(fourWeekWindowLocations.value)) return {};

    // Group by date
    const groupedByDate = fourWeekWindowLocations.value.reduce((groups, location) => {
        const dateKey = location.formatted_date;
        if (!groups[dateKey]) groups[dateKey] = [];
        groups[dateKey].push(location);
        return groups;
    }, {});

    // Sort locations within each date group by time
    Object.keys(groupedByDate).forEach(dateKey => {
        groupedByDate[dateKey].sort((a, b) => {
            return parseTimeToDate(a.time) - parseTimeToDate(b.time);
        });
    });

    // Sort date keys chronologically
    return Object.fromEntries(
        Object.entries(groupedByDate)
            .sort(([dateA], [dateB]) => {
                // Get first location from each group to compare dates
                const locationA = groupedByDate[dateA][0];
                const locationB = groupedByDate[dateB][0];
                return locationA.date_obj - locationB.date_obj;
            })
    );
});

// Helper function to parse time string to Date object for comparison
function parseTimeToDate(timeString) {
    if (!timeString) return new Date(0);
    
    try {
        // Handle "7:00PM" or "7:00 PM" format
        if (timeString.includes('AM') || timeString.includes('PM')) {
            const timeParts = timeString.replace('AM', ' AM').replace('PM', ' PM').trim().split(' ');
            const [hours, minutes] = timeParts[0].split(':');
            const isPM = timeParts[1] === 'PM';
            
            const date = new Date();
            date.setHours(isPM && parseInt(hours) < 12 ? parseInt(hours) + 12 : parseInt(hours));
            date.setMinutes(parseInt(minutes));
            date.setSeconds(0);
            
            return date;
        }
        
        // Handle 24-hour format "HH:MM"
        if (timeString.includes(':')) {
            const [hours, minutes] = timeString.split(':');
            const date = new Date();
            date.setHours(parseInt(hours));
            date.setMinutes(parseInt(minutes));
            date.setSeconds(0);
            
            return date;
        }
        
        return new Date(0); // Default
    } catch (e) {
        console.error("Error parsing time:", e);
        return new Date(0);
    }
}

// Function to handle location selection
const handleLocationSelect = (event) => {
    const locationId = event.target.value;
    const location = props.locations.find(loc => loc.id === parseInt(locationId));
    if (location) {
        selectedLocationData.value = {
            id: location.id,
            name: location.name,
            date: formatDate(location.date),
            time: formatTime(location.time)
        };
        // Add location ID to formValues with the correct key
        formValues.value['events_location'] = location.id;
    }
};

// const marketingPermission = ref(true); // ✅ Track checkbox state

// ✅ Get CSRF token from Laravel
const csrfToken = computed(() => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
});

// Phone number formats for different countries
const phoneFormats = {
    'Australia': '## #### ####',
    'New Zealand': '## ### ####',
    'USA': '(###) ###-####',
    'Canada': '(###) ###-####',
    'Germany': '#### ######',
    'United Kingdom': '#### ### ####',
    'Europe': '## ### ####'
};

// Add a function to handle checkbox changes
const handleCheckboxChange = (question, option) => {
    if (!formValues.value[question.text]) {
        formValues.value[question.text] = [];
    }
    
    const index = formValues.value[question.text].indexOf(option);
    if (index === -1) {
        formValues.value[question.text].push(option);
    } else {
        formValues.value[question.text].splice(index, 1);
    }
};

// Add a function to check if an option is selected
const isOptionSelected = (question, option) => {
    return formValues.value[question.text]?.includes(option) || false;
};

// Handle form submission
const submitForm = async () => {
    if (isSubmitting.value) return; // Prevent multiple submissions
    
    // ✅ Validate if location is selected
    if (!selectedLocation.value) {
        Swal.fire('Error!', 'Please select a location.', 'error');
        return;
    }

    // 🚨 Check mobile number before submission
    if (!isMobileNumberValid.value) {
        Swal.fire('Error!', 'Please enter a valid mobile number.', 'error');
        return;
    }

    isSubmitting.value = true; // Set loading state

    // If compliance state, silently resubscribe via JSONP first, then submit form
    if (isComplianceState.value && complianceSignupUrl.value) {
        try {
            await silentMailchimpResub();
        } catch (e) {
            // Don't block form submission if resub fails
            console.warn('Silent resub failed:', e);
        }
    }

    const submissionValues = { ...formValues.value };

    // Process "Other" values and multiple selections
    JSON.parse(props.form.questions).forEach(question => {
        if (question.allowMultiple) {
            // Handle multiple selections
            if (Array.isArray(submissionValues[question.text])) {
                // If "Other" is selected and has a value, replace it in the array
                const otherIndex = submissionValues[question.text].indexOf('Other');
                if (otherIndex !== -1 && otherValues.value[question.column_name]) {
                    submissionValues[question.text][otherIndex] = otherValues.value[question.column_name];
                }
                // Join the array with commas
                submissionValues[question.text] = submissionValues[question.text].join(', ');
            }
        } else {
            // Handle single selection
            if (question.hasOtherOption && submissionValues[question.text] === 'Other') {
                submissionValues[question.text] = otherValues.value[question.column_name] || 'Other';
            }
        }
    });

    router.post(route('signup.storeEmbedded', { event_uuid: props.event.event_uuid }), {
        ...submissionValues,
        _token: csrfToken.value
    }, {
        onSuccess: () => {
            isSubmitting.value = false; // Reset loading state
            Swal.fire('Success!', 'Your sign-up has been submitted.', 'success');
            formValues.value = {}; // Clear form after submission
            selectedLocation.value = ''; // Clear location selection
            selectedLocationData.value = { id: null, name: '', date: '', time: '' }; // Clear location data
            isSubmitted.value = true; // ✅ Show thank-you card
        },
        onError: (errors) => {
            isSubmitting.value = false; // Reset loading state
            if(errors.email){
                Swal.fire('Error!', errors.email, 'error');
            }
            else{
                Swal.fire('Error!', 'An error occurred. Please try again.', 'error');
                console.error(errors);
            }
        }
    });
};

// ✅ Computed property to check if the phone number is complete
const isMobileNumberValid = computed(() => {
    const phoneNumber = formValues.value[mobileNumberQuestionText.value] || '';
    
    console.log('🔍 Phone Validation Debug:', {
        mobileNumberField: mobileNumberQuestionText.value,
        phoneNumber: phoneNumber,
        phoneNumberLength: phoneNumber.length,
        hasAddressFields: hasAddressFields.value
    });
    
    if (!phoneNumber) {
        console.log('❌ Phone number is empty');
        return false;
    }

    // If address fields are not being collected, allow a generic digit-length validation
    if (!hasAddressFields.value) {
        const digits = phoneNumber.replace(/\D/g, '').length;
        const isValid = digits >= 8; // generic minimum when country is unknown
        console.log('📱 No address fields - Generic validation:', {
            digits: digits,
            isValid: isValid
        });
        return isValid;
    }

    const country = formValues.value[countryQuestionText.value];
    console.log('🌍 Country check:', {
        countryField: countryQuestionText.value,
        country: country,
        availableFormats: Object.keys(phoneFormats)
    });
    
    const format = phoneFormats[country];
    if (!format) {
        console.log('❌ No format found for country:', country);
        return false;
    }

    // Count how many digits are required in the format
    const requiredDigits = (format.match(/#/g) || []).length;
    const enteredDigits = phoneNumber.replace(/\D/g, '').length;
    const isValid = enteredDigits === requiredDigits;

    console.log('✅ Format validation:', {
        format: format,
        requiredDigits: requiredDigits,
        enteredDigits: enteredDigits,
        isValid: isValid
    });

    return isValid;
});

const formatPhoneNumber = (fieldName, format) => {
    if (!formValues.value[fieldName]) return;

    // If no format provided, keep only allowed chars but don't force a mask
    if (!format) {
        formValues.value[fieldName] = formValues.value[fieldName].replace(/[^\d()+\-.\s]/g, '');
        return;
    }

    let rawValue = formValues.value[fieldName].replace(/\D/g, '');
    let formattedNumber = '';
    let rawIndex = 0;

    for (let i = 0; i < format.length; i++) {
        if (format[i] === '#') {
            if (rawIndex < rawValue.length) {
                formattedNumber += rawValue[rawIndex++];
            }
        } else {
            if (rawIndex < rawValue.length) {
                formattedNumber += format[i];
            }
        }
    }

    formValues.value[fieldName] = formattedNumber;
};

// ✅ Convert date to readable format (e.g., March 31, 2025)
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// Format date to "March 07, 2025" using only the date string (no timezone conversion)
function formatDate(dateString) {
    if (!dateString) return '';
    try {
        if (dateString.includes(',')) return dateString;
        const match = String(dateString).trim().match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (!match) return dateString;
        const [, y, m, d] = match;
        const monthIdx = parseInt(m, 10) - 1;
        if (monthIdx < 0 || monthIdx > 11) return dateString;
        const day = parseInt(d, 10);
        const month = MONTH_NAMES[monthIdx];
        return `${month} ${String(day).padStart(2, '0')}, ${y}`;
    } catch (e) {
        console.error("Error formatting date:", e);
        return dateString;
    }
}

// Format time to "7:00PM" using only the time string (no timezone conversion)
function formatTime(timeString) {
    if (!timeString) return '';
    try {
        if (timeString.includes('AM') || timeString.includes('PM')) {
            return timeString.replace(/\s+/g, '');
        }
        if (timeString.includes(':')) {
            const parts = timeString.split(':');
            let hours = parseInt(parts[0], 10) || 0;
            const minutes = parseInt(parts[1], 10) || 0;
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            return `${hours}:${String(minutes).padStart(2, '0')}${ampm}`;
        }
        return timeString;
    } catch (e) {
        console.error("Error formatting time:", e);
        return timeString;
    }
}

// Computed property to filter options
const getVisibleOptions = (question) => {
    
    if(!question.hiddenOptions) {
        question.hiddenOptions = [];
    }
    return question.options.filter(option => !question.hiddenOptions.includes(option));
};

// Reset subscription status when email changes
watch(() => formValues.value[emailQuestionText.value], () => {
    if (isSubscriptionChecked.value) {
        subscriptionStatus.value = null;
        isSubscriptionChecked.value = false;
        showResubscribePrompt.value = false;
        isComplianceState.value = false;
        complianceSignupUrl.value = null;
    }
});

// Watch for changes in the country field and update the phone number format
watch(() => formValues.value[countryQuestionText.value], (newCountry) => {
    console.log('🌍 Country changed:', {
        countryField: countryQuestionText.value,
        newCountry: newCountry
    });
    
    formValues.value[mobileNumberQuestionText.value] = '';

    if (!newCountry) {
        return;
    }

    const format = phoneFormats[newCountry];
    console.log('📞 Phone format for country:', {
        country: newCountry,
        format: format,
        mobileField: mobileNumberQuestionText.value
    });

    if (format) {
        formatPhoneNumber(mobileNumberQuestionText.value, format);
    } else {
        // No specific mask; leave number unformatted
        console.log('⚠️ No format found for country:', newCountry);
    }
});

// Find the email question text dynamically
const emailQuestionText = computed(() => {
    const questions = JSON.parse(props.form.questions || '[]');
    const emailQuestion = questions.find(q => q.type === 'email' || q.column_name === 'email_address');
    return emailQuestion ? emailQuestion.text : 'Email Address';
});

// Check email subscription status with Mailchimp
const checkEmailSubscription = async () => {
    const email = formValues.value[emailQuestionText.value];
    if (!email || !email.includes('@')) {
        subscriptionStatus.value = null;
        isSubscriptionChecked.value = false;
        showResubscribePrompt.value = false;
        return;
    }

    subscriptionStatus.value = 'checking';

    try {
        const response = await fetch(route('signup.checkSubscription', { event_uuid: props.event.event_uuid }), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken.value,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ email }),
        });

        const data = await response.json();

        if (data.subscribed) {
            subscriptionStatus.value = 'subscribed';
            isSubscriptionChecked.value = true;
            showResubscribePrompt.value = false;
            isComplianceState.value = false;
            complianceSignupUrl.value = null;
        } else {
            subscriptionStatus.value = 'not_subscribed';
            isSubscriptionChecked.value = true;
            newsletterAudienceName.value = data.audience || 'the newsletter';

            // Check if member is in compliance state (self-unsubscribed via Mailchimp)
            if (data.compliance_state) {
                isComplianceState.value = true;
                complianceSignupUrl.value = data.mailchimp_signup_url || null;
                showResubscribePrompt.value = false;

            } else {
                isComplianceState.value = false;
                complianceSignupUrl.value = null;
                showResubscribePrompt.value = true;
            }
        }
    } catch (error) {
        console.error('Subscription check failed:', error);
        // On error, allow entry
        subscriptionStatus.value = 'subscribed';
        isSubscriptionChecked.value = true;
        showResubscribePrompt.value = false;
        isComplianceState.value = false;
        complianceSignupUrl.value = null;
    }
};

// Silently resubscribe to Mailchimp via JSONP (called on form submit)
const buildMailchimpJsonpUrl = (url) => {
    if (url.includes('/subscribe/post?')) {
        return url.replace('/subscribe/post?', '/subscribe/post-json?');
    }
    if (url.includes('/subscribe?')) {
        return url.replace('/subscribe?', '/subscribe/post-json?');
    }
    return url;
};

const silentMailchimpResub = () => {
    return new Promise((resolve, reject) => {
        const email = formValues.value[emailQuestionText.value] || '';
        if (!email) return resolve();

        let jsonpUrl = buildMailchimpJsonpUrl(complianceSignupUrl.value);
        const separator = jsonpUrl.includes('?') ? '&' : '?';
        jsonpUrl += separator + 'EMAIL=' + encodeURIComponent(email);
        jsonpUrl += '&tags=7216898';

        const callbackName = 'mc_resub_callback_' + Date.now();
        jsonpUrl += '&c=' + callbackName;

        const timeout = setTimeout(() => {
            delete window[callbackName];
            const s = document.getElementById(callbackName);
            if (s) s.remove();
            resolve(); // Don't block on timeout
        }, 5000);

        window[callbackName] = (data) => {
            clearTimeout(timeout);
            delete window[callbackName];
            const s = document.getElementById(callbackName);
            if (s) s.remove();
            resolve(data);
        };

        const script = document.createElement('script');
        script.id = callbackName;
        script.src = jsonpUrl;
        script.onerror = () => {
            clearTimeout(timeout);
            delete window[callbackName];
            script.remove();
            resolve(); // Don't block on error
        };
        document.body.appendChild(script);
    });
};

const newsletterAudienceName = ref('the newsletter');

// Auto-recheck subscription when user comes back to the tab (after resubscribing on Mailchimp)
const handleVisibilityChange = () => {
    if (document.visibilityState === 'visible' && isComplianceState.value) {
        checkEmailSubscription();
    }
};

onMounted(() => {
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onUnmounted(() => {
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});

// Check if there are any events in the four-week window
const hasEventsInWindow = computed(() => {
    return Object.keys(groupedLocations.value).length > 0;
});

// Helper to determine if a date is in the past
const isPastDate = (dateObj) => {
    const todayStart = new Date(today);
    todayStart.setHours(0, 0, 0, 0);
    return dateObj < todayStart;
};

// Add a visual indicator for past dates
const getDateLabelClass = (dateKey) => {
    const firstLocation = groupedLocations.value[dateKey][0];
    return isPastDate(firstLocation.date_obj) ? '' : '';
};

onMounted(() => {
  // Log the parsed questions to see if hasOtherOption exists
  const questions = JSON.parse(props.form.questions);
//   console.log('Parsed questions:', questions);
  
  // Check for hasOtherOption presence
  const hasOtherQuestions = questions.filter(q => q.hasOtherOption);
//   console.log('Questions with hasOtherOption:', hasOtherQuestions);
});
</script>

<template>
    <div class="container mt-4 mb-4 d-flex justify-content-center align-items-center flex-column" style="min-height: 100vh;">
        
        <!-- Form Container -->
        <div class="text-center mb-4 border rounded shadow-sm bg-light col-12 col-md-8 col-lg-5">
            <div class="w-100">
                <img :src="'/storage/' + event.event_banner" alt="Event Banner" 
                     class="img-fluid w-100" 
                     style="object-fit: contain; max-height: 500px;">
            </div>

            <!-- ✅ Thank You Card (Shown after submission) -->
            <div v-if="isSubmitted" class="text-center p-5 border rounded shadow-sm bg-light">
                <h2 class="mb-3 fw-bold" style="font-size: 20px;">Thank you for joining!</h2>
                <p class="mb-3">You're now part of our community—stay tuned for exciting news, updates, and the chance to win amazing prizes!</p>
            </div>

            <!-- ✅ Event Details (Shown before submission) -->
            <div class="p-4" v-if="!isSubmitted">
                <h2 class="mt-2 mb-3 fw-bold" style="font-size: 19px;">{{ form.heading }}</h2>
                <p class="mb-3" style="font-size: 14px;">{{ form.event_description }}</p>
                <p class="mb-2">
                    <a :href="form.terms_link" target="_blank" class="text-decoration-none" style="color: #0000EE; font-size: 14px;">Terms and Conditions</a> |
                    <a :href="form.privacy_link" target="_blank" class="text-decoration-none" style="color: #0000EE; font-size: 14px;">Privacy Policy</a>
                </p>
            </div>
        </div>

        <!-- ✅ Sign-up Form (Hidden after submission) -->
        <form @submit.prevent="submitForm" 
              class="p-3 border rounded shadow-sm bg-light col-12 col-md-8 col-lg-5" 
              v-if="!isSubmitted">
            <input type="hidden" :value="csrfToken" name="_token">

            <label class="block font-medium text-gray-800 mb-1">Events Location</label>
            
            <!-- Show location dropdown if there are events in the window -->
            <div v-if="hasEventsInWindow">
                <select 
                    v-model="selectedLocation" 
                    @change="handleLocationSelect"
                    class="form-select mb-3 w-full border rounded px-3 py-2"
                    required
                >
                    <option value="" disabled selected>Select a location</option>
                    
                    <optgroup 
                        v-for="(locations, date) in groupedLocations" 
                        :label="date" 
                        :key="date"
                        :class="getDateLabelClass(date)"
                    >
                        <option 
                            v-for="location in locations" 
                            :key="location.id" 
                            :value="location.id"
                        >
                            {{ location.name }} - {{ location.formatted_time }}
                        </option>
                    </optgroup>
                </select>
            </div>
            
            <!-- Message when no events in window -->
            <div v-else class="alert alert-info mb-3">
                No events available in the selected date range. Please check back later.
            </div>

            <!-- Show selected location details -->
            <div v-if="selectedLocationData.id" class="mb-4 p-3 bg-gray-50 rounded">
                <p class="text-sm text-gray-600">Selected Location:</p>
                <p class="font-medium">{{ selectedLocationData.name }}</p>
                <p class="text-sm text-gray-600">{{ selectedLocationData.date }} at {{ selectedLocationData.time }}</p>
            </div>

            <template v-for="(question, index) in JSON.parse(form.questions)" :key="index">
                <label class="form-label" v-if="question.column_name !== 'street_address' && question.column_name !== 'street_address_2' && question.column_name !== 'city' && question.column_name !== 'state' && question.column_name !== 'zip_code' && question.column_name !== 'country'" >{{ question.text }}</label>
                <label class="form-label" v-if="question.column_name === 'street_address'">Address</label>
                <!-- ✅ Text & Number Inputs -->
                <template v-if="question.type === 'text' && question.column_name !== 'street_address' && question.column_name !== 'street_address_2' && question.column_name !== 'city' && question.column_name !== 'state' && question.column_name !== 'zip_code' && question.column_name !== 'country'" >
                    <div class="pb-3">
                        <input v-model="formValues[question.text]" type="text" class="form-control  w-full border rounded px-3 py-2" required>
                    </div>
                </template>

                <template v-if="question.type === 'email'">
                    <div class="pb-3">
                        <input
                            v-model="formValues[question.text]"
                            type="email"
                            class="form-control w-full border rounded px-3 py-2"
                            required
                            @blur="checkEmailSubscription"
                        >
                    </div>
                </template>

                <template v-if="question.type === 'date'">
                    <div class="pb-3">
                        <input v-model="formValues[question.text]" type="date" class="form-control w-full border rounded px-3 py-2" required>
                    </div>
                </template>

                <template v-if="question.type === 'textarea'">
                    <div class="pb-3">
                        <textarea v-model="formValues[question.text]" class="form-control w-full border rounded px-3 py-2" rows="4" required></textarea>
                    </div>
                </template>

                <template v-if="question.type === 'number' && question.column_name === 'mobile_number'">
                    <div class="pb-3 ">
                        <input 
                            v-model="formValues[question.text]" 
                            class="form-control"
                            required
                            :disabled="hasAddressFields && !formValues[countryQuestionText]"
                            @input="formatPhoneNumber(question.text, phoneFormats[formValues[countryQuestionText]])"
                        >
                    </div>
                </template>

                <!-- ✅ Dropdowns -->
                <template v-else-if="question.type === 'dropdown' && question.column_name !== 'country'">
                    <div class="pb-3">
                        <template v-if="question.allowMultiple">
                            <div v-for="option in getVisibleOptions(question)" :key="option" class="mb-2">
                                <label class="flex items-center">
                                    <input 
                                        type="checkbox" 
                                        :value="option"
                                        v-model="formValues[question.text]"
                                        class="mr-2"
                                    />
                                    {{ option }}
                                </label>
                            </div>
                            <!-- Add the "Other" option if hasOtherOption is true -->
                            <div v-if="question.hasOtherOption" class="mb-2">
                                <label class="flex items-center">
                                    <input 
                                        type="checkbox" 
                                        value="Other"
                                        v-model="formValues[question.text]"
                                        class="mr-2"
                                    />
                                    Other
                                </label>
                                <input 
                                    v-if="formValues[question.text]?.includes('Other')" 
                                    v-model="otherValues[question.column_name]"
                                    type="text" 
                                    class="form-control mt-2 w-full border rounded px-3 py-2" 
                                    placeholder="Please specify..."
                                    required
                                >
                            </div>
                        </template>
                        <template v-else>
                            <select 
                                v-model="formValues[question.text]" 
                                class="form-select w-full border rounded px-3 py-2" 
                                required
                                @change="checkForOther(question)"
                            >
                                <option value="">Select an option</option>
                                <option v-for="option in getVisibleOptions(question)" :key="option" :value="option">
                                    {{ option }}
                                </option>
                                <!-- Add the "Other" option if hasOtherOption is true -->
                                <option v-if="question.hasOtherOption" value="Other">Other</option>
                            </select>
                            
                            <!-- Add the "Other" input field -->
                            <input 
                                v-if="question.hasOtherOption && formValues[question.text] === 'Other'" 
                                v-model="otherValues[question.column_name]"
                                type="text" 
                                class="form-control mt-2 w-full border rounded px-3 py-2" 
                                placeholder="Please specify..."
                                required
                            >
                        </template>
                    </div>
                </template>

                <!-- ✅ Address Fields -->
                <template v-if="question.column_name === 'street_address' || question.column_name === 'street_address_2' || question.column_name === 'city' || question.column_name === 'state' || question.column_name === 'zip_code' || question.column_name === 'country'">
                    <div class="pb-2">
                        <div class="d-flex ">
                            <input v-if="question.column_name === 'street_address'" v-model="formValues[question.text]" type="text" class="form-control  w-full border rounded px-3 py-2" placeholder="Street Address" required>
                            <input v-if="question.column_name === 'street_address_2'" v-model="formValues[question.text]" type="text" class="form-control  w-full border rounded px-3 py-2" placeholder="Address Line 2">
                            <input v-if="question.column_name === 'city'" v-model="formValues[question.text]" type="text" class="flex-1 form-control  w-full border rounded px-3 py-2" placeholder="City" required>
                            <input v-if="question.column_name === 'state'" v-model="formValues[question.text]" type="text" class="flex-1 form-control  w-full border rounded px-3 py-2" placeholder="State" required>
                            <input v-if="question.column_name === 'zip_code'" v-model="formValues[question.text]" type="number" class="flex-1 form-control  w-full border rounded px-3 py-2" placeholder="Zip Code" required>                
                        </div>
                        <label class="form-label" v-if="question.column_name === 'country'">{{ countryQuestionText }}</label>
                        <div v-if="question.column_name === 'country'">
                            <select v-model="formValues[question.text]" class="form-select  w-full border rounded px-3 py-2" required>
                            <option value="">Select an option</option>
                            <option v-for="option in getVisibleOptions(question)" :key="option" :value="option">{{ option }}</option>
                            </select>       
                        </div>  
                    </div>
                </template>
            </template>

            <!-- ✅ Marketing Permission Checkbox (Required) -->
            <div class="form-check mt-2">
                <!-- <input v-model="marketingPermission" type="checkbox" class="form-check-input" id="marketingPermission" required> -->
                <label class="form-check-label" for="marketingPermission">
                    <strong>Marketing Permission</strong> <br>
                    <!-- By checking the box, you accept the competition terms and conditions and consent to receive marketing materials related to the offerings of Adventure Entertainment and our partners. -->
                    By submitting this form, you agree to the competition terms and conditions and authorize us to send you marketing materials about Adventure Entertainment and our partners' offerings.
                </label>
            </div>

            <div class="d-flex justify-content-center mt-4 mb-3">
                <button
                    type="submit"
                    class="btn btn-primary w-40"
                    :disabled="!hasEventsInWindow || isSubmitting || subscriptionStatus === 'checking'"
                >
                    <span v-if="subscriptionStatus === 'checking'">
                        <i class="fa-solid fa-spinner fa-spin me-2"></i>
                        Verifying...
                    </span>
                    <span v-else-if="isSubmitting">
                        <i class="fa-solid fa-spinner fa-spin me-2"></i>
                        Submitting...
                    </span>
                    <span v-else>Submit</span>
                </button>
            </div>
        </form>
    </div>
</template>