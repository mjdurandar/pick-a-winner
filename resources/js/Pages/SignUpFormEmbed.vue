<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';

const hasAddressFields = computed(() => {
    return JSON.parse(props.form.questions).some(question => 
        ['street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country'].includes(question.column_name)
    );
});


const props = defineProps({
    form: Object,
    event: Object,
    locations: Array
});

// Store form values
const formValues = ref({});
const isSubmitted = ref(false);
const selectedLocation = ref('');
const selectedLocationData = ref({
    id: null,
    name: '',
    date: '',
    time: ''
});

// Function to handle location selection
const handleLocationSelect = (event) => {
    const locationId = event.target.value;
    const location = props.locations.find(loc => loc.id === parseInt(locationId));
    if (location) {
        selectedLocationData.value = {
            id: location.id,
            name: location.name,
            date: location.date,
            time: location.time
        };
        // Add location ID to formValues with the correct key
        formValues.value['events_location'] = location.id;
    }
};

const marketingPermission = ref(false); // ✅ Track checkbox state

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

// Handle form submission
const submitForm = () => {
    // ✅ Validate if location is selected
    if (!selectedLocation.value) {
        Swal.fire('Error!', 'Please select a location.', 'error');
        return;
    }

    // ✅ Validate if the checkbox is checked
    if (!marketingPermission.value) {
        Swal.fire('Error!', 'You must accept the Marketing Permission terms.', 'error');
        return;
    }

    // 🚨 Check mobile number before submission
    if (!isMobileNumberValid.value) {
        Swal.fire('Error!', 'Please enter a valid mobile number.', 'error');
        return;
    }

    router.post(route('signup.storeEmbedded', { eventId: props.event.id }), { 
        ...formValues.value,
        marketing_permission: marketingPermission.value,
        _token: csrfToken.value
    }, {
        onSuccess: () => {
            Swal.fire('Success!', 'Your sign-up has been submitted.', 'success');
            formValues.value = {}; // Clear form after submission
            selectedLocation.value = ''; // Clear location selection
            selectedLocationData.value = { id: null, name: '', date: '', time: '' }; // Clear location data
            marketingPermission.value = false; // Reset checkbox
            isSubmitted.value = true; // ✅ Show thank-you card
        },
        onError: (errors) => {
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
    const country = formValues.value['Country'];
    const format = phoneFormats[country];
    const phoneNumber = formValues.value['Mobile Number'] || '';

    if (!format || !phoneNumber) return false;

    // Count how many digits are required in the format
    const requiredDigits = (format.match(/#/g) || []).length;
    const enteredDigits = phoneNumber.replace(/\D/g, '').length;

    return enteredDigits === requiredDigits;
});

const formatPhoneNumber = (fieldName, format) => {
    if (!formValues.value[fieldName]) return;

    let rawValue = formValues.value[fieldName].replace(/\D/g, ''); // Remove non-numeric characters
    let formattedNumber = '';
    let rawIndex = 0;

    for (let i = 0; i < format.length; i++) {
        if (format[i] === '#') {
            if (rawIndex < rawValue.length) {
                formattedNumber += rawValue[rawIndex++];
            }
        } else {
            if (rawIndex < rawValue.length) {
                formattedNumber += format[i]; // Add separator if there's still numbers left
            }
        }
    }

    formValues.value[fieldName] = formattedNumber;
};


// Computed property to filter options
const getVisibleOptions = (question) => {
    
    if(!question.hiddenOptions) {
        question.hiddenOptions = [];
    }
    return question.options.filter(option => !question.hiddenOptions.includes(option));
};

// Watch for changes in the country field and update the phone number format
watch(() => formValues.value['Country'], (newCountry) => {
    console.log('Country changed:', newCountry); // Debugging log
    formValues.value['mobile_number'] = '';

    if (!newCountry) {
        return;
    }

    const format = phoneFormats[newCountry];

    if (format) {
        formatPhoneNumber('Mobile Number', format);
    } else {
        console.log('No format found for', newCountry);
    }
});

</script>

<template>
    <div class="container mt-4 mb-4 d-flex justify-content-center align-items-center flex-column" style="min-height: 100vh;">
        
        <!-- Form Container -->
        <div class="text-center mb-4 border rounded shadow-sm bg-light col-12 col-md-8 col-lg-5">
            <div class="w-100">
                <img :src="'/storage/' + event.event_banner" alt="Event Banner" 
                     class="img-fluid w-100" 
                     style=" height: 350px;">
            </div>

            <!-- ✅ Thank You Card (Shown after submission) -->
            <div v-if="isSubmitted" class="text-center p-5 border rounded shadow-sm bg-light">
                <h2 class="mb-3 fw-bold" style="font-size: 20px;">Thank You for Signing Up!</h2>
                <p class="mb-3">We've received your submission. We will contact you soon.</p>
            </div>

            <!-- ✅ Event Details (Shown before submission) -->
            <div class="p-4" v-if="!isSubmitted">
                <h2 class="mt-2 mb-3 fw-bold" style="font-size: 20px;">{{ form.heading }}</h2>
                <p class="mb-3">{{ form.event_description }}</p>
                <p class="mb-2">
                    <a :href="form.terms_link" target="_blank" class="text-decoration-none" style="color: #0000EE;">Terms and Conditions</a> |
                    <a :href="form.privacy_link" target="_blank" class="text-decoration-none" style="color: #0000EE;">Privacy Policy</a>
                </p>
            </div>
        </div>

        <!-- ✅ Sign-up Form (Hidden after submission) -->
        <form @submit.prevent="submitForm" 
              class="p-3 border rounded shadow-sm bg-light col-12 col-md-8 col-lg-5" 
              v-if="!isSubmitted">
            <input type="hidden" :value="csrfToken" name="_token">

            <label class="block font-medium text-gray-800 mb-1">Events Location</label>
            <select 
                v-model="selectedLocation" 
                @change="handleLocationSelect"
                class="form-select mb-3 w-full border rounded px-3 py-2"
                required
            >
                <option value="" disabled>Select a location</option>
                <option v-for="location in props.locations" :key="location.id" :value="location.id">
                    {{ location.name }} - {{ location.date }} - {{ location.time }}
                </option>
            </select>

            <!-- Show selected location details -->
            <!-- <div v-if="selectedLocationData.id" class="mb-4 p-3 bg-gray-50 rounded">
                <p class="text-sm text-gray-600">Selected Location:</p>
                <p class="font-medium">{{ selectedLocationData.name }}</p>
                <p class="text-sm text-gray-600">{{ selectedLocationData.date }} at {{ selectedLocationData.time }}</p>
            </div> -->

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
                        <input v-model="formValues[question.text]" type="email" class="form-control  w-full border rounded px-3 py-2" required>
                    </div>
                </template>

                <template v-if="question.type === 'number' && question.column_name === 'mobile_number'">
                    <div class="pb-3 ">
                        <input 
                            v-model="formValues[question.text]" 
                            class="form-control"
                            required
                            :disabled="hasAddressFields && !formValues['Country']"
                            @input="formatPhoneNumber(question.text, phoneFormats[formValues['Country']])"
                        >
                    </div>
                </template>

                <!-- ✅ Dropdowns -->
                 <template v-else-if="question.type === 'dropdown' && question.column_name !== 'country'" >
                    <div class="pb-3">
                        <select v-model="formValues[question.text]" class="form-select  w-full border rounded px-3 py-2" required>
                        <option value="">Select an option</option>
                        <option v-for="option in getVisibleOptions(question)" :key="option" :value="option">{{ option }}</option>
                    </select>
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
                        <label class="form-label" v-if="question.column_name === 'country'">Country</label>
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
                <input v-model="marketingPermission" type="checkbox" class="form-check-input" id="marketingPermission" required>
                <label class="form-check-label" for="marketingPermission">
                    <strong>Marketing Permission</strong> <br>
                    By checking the box, you accept the competition terms and conditions and consent to receive marketing materials related to the offerings of Adventure Entertainment and our partners.
                </label>
            </div>

            <div class="d-flex justify-content-center mt-4 mb-3">
                <button type="submit" class="btn btn-primary w-40">Submit</button>
            </div>
        </form>
    </div>
</template>
