<script setup>
import { ref, watchEffect, computed } from 'vue';
import { router, Head, usePage } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

// ✅ Receive `eventId` and `form` as props
const props = defineProps({ 
    eventId: Number, 
    eventValues: Object,
    form: Object,
    locations: Array
});

// ✅ Get user role from Inertia
const page = usePage();
const user = computed(() => page.props.auth.user || null);
const userRole = computed(() => user.value?.role); 

const selectedLocation = ref('');
// ✅ Store form data reactively
const signupForm = ref(props.form || null);
// ✅ Generate Signup Form Link
const signupFormUrl = computed(() => {
    return `${window.location.origin}/adventureentertainment/form/${props.eventId}`;
});

// Format locations to display date and time in the desired format
const formattedLocations = computed(() => {
    if (!props.locations || !Array.isArray(props.locations)) return [];
    
    return props.locations.map(location => {
        return {
            ...location,
            formatted_date: formatDate(location.date),
            formatted_time: formatTime(location.time)
        };
    });
});

// Function to format date to "March 07, 2025" format
function formatDate(dateString) {
    if (!dateString) return '';
    
    try {
        // If date is already in a format like "March 7, 2025", no need to reformat
        if (dateString.includes(',')) {
            return dateString;
        }
        
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString; // Return original if invalid
        
        return date.toLocaleDateString('en-US', {
            month: 'long',
            day: '2-digit',
            year: 'numeric'
        });
    } catch (e) {
        console.error("Error formatting date:", e);
        return dateString;
    }
}

// Function to format time to "7:00PM" format
function formatTime(timeString) {
    if (!timeString) return '';
    
    try {
        // If time is already in a format like "7:00 PM", no need to reformat
        if (timeString.includes('AM') || timeString.includes('PM')) {
            // Remove space between time and AM/PM if exists
            return timeString.replace(' ', '');
        }
        
        // For 24-hour format "HH:MM"
        if (timeString.includes(':')) {
            const [hours, minutes] = timeString.split(':');
            const date = new Date();
            date.setHours(parseInt(hours));
            date.setMinutes(parseInt(minutes));
            
            return date.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            }).replace(' ', ''); // Remove space between time and AM/PM
        }
        
        return timeString;
    } catch (e) {
        console.error("Error formatting time:", e);
        return timeString;
    }
}

// ✅ Copy Signup Form URL to Clipboard
const copySignupFormUrl = () => {
    navigator.clipboard.writeText(signupFormUrl.value).then(() => {
        Swal.fire({
            icon: 'success',
            title: 'Signup Link Copied!',
            text: 'Paste this link anywhere to share the signup form.',
            timer: 2500,
            showConfirmButton: false
        });
    }).catch(() => {
        Swal.fire({
            icon: 'error',
            title: 'Failed to Copy',
            text: 'Please copy the link manually.',
        });
    });
};

const submitTest = () => {
    Swal.fire('Submitted!', 'This is a test submission. No data has been received. Please copy the URL link and submit the data.', 'success');
};

const createSignUpForm = () => {
    router.get(route('signup.create', { eventId: props.eventId }));
};

// ✅ Redirect to edit form page
const editSignUpForm = () => {
    if (signupForm.value) {
        router.get(route('signup.edit', { formId: signupForm.value.id }));
    }
};

const groupedLocations = computed(() => {
    if (!formattedLocations.value || !Array.isArray(formattedLocations.value)) return {}; // Prevent errors

    return formattedLocations.value.reduce((groups, location) => {
        if (!location.date) return groups; // Skip invalid entries

        const formattedDate = convertToISODate(location.date); // Convert date to valid format

        if (!groups[formattedDate]) {
            groups[formattedDate] = [];
        }
        groups[formattedDate].push(location);
        return groups;
    }, {});
});

// Function to convert date to a consistent format
const convertToISODate = (dateString) => {
    try {
        return new Date(dateString).toISOString().split("T")[0]; // Format as YYYY-MM-DD
    } catch (error) {
        console.error("Invalid date:", dateString, error);
        return "";
    }
};

// ✅ Watch for changes in `props.form` and update `signupForm`
watchEffect(() => {
    signupForm.value = props.form;
});
</script>

<template>
    <Head title="Sign Up Form" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col md:flex-row justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800 text-center md:text-left">
                    Sign Up Form for Event: {{ eventValues.event_name }}
                </h2>
                <div class="flex mt-4 md:mt-0">
                    <!-- ✅ Show this only if signupForm exists -->
                    <button v-if="signupForm" @click="copySignupFormUrl()" class="btn btn-success me-3">
                        Copy Signup Link
                    </button>

                    <!-- ✅ Show "Edit Form" only if signupForm exists and user is admin -->
                    <button v-if="signupForm && userRole === 'admin'" @click="editSignUpForm()" class="btn btn-warning">
                        Edit Form
                    </button>  

                    <!-- ✅ Show "Generate Sign Up Form" only if signupForm does NOT exist -->
                    <button v-if="!signupForm && userRole === 'admin'" @click="createSignUpForm()" class="btn btn-primary">
                        Create Sign Up Form
                    </button>
                </div>
            </div>
        </template>

        <div class="container mt-4 mb-4 pb-4 d-flex justify-content-center align-items-center flex-column">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- ✅ If a signup form exists, display it -->
                <div v-if="signupForm">
                    <!-- Form Container -->
                    <div class="text-center border rounded shadow-sm bg-light col-12 col-md-8 col-lg-5 bg-white w-full md:w-3/5 mx-auto rounded shadow-lg">
                        <div class="w-100">
                            <img :src="'/storage/' + eventValues.event_banner" alt="Event Banner" 
                                class="img-fluid w-100" 
                                style="object-fit: cover; height: 350px;">
                        </div>

                        <div class="p-4">
                            <h2 class="mt-2 mb-3 fw-bold" style="font-size: 20px;">{{ form.heading }}</h2>
                            <p class="mb-3">{{ form.event_description }}</p>
                            <p class="mb-2">
                                <a :href="form.terms_link" target="_blank" class="text-decoration-none" style="color: #0000EE;">Terms and Conditions</a> |
                                <a :href="form.privacy_link" target="_blank" class="text-decoration-none" style="color: #0000EE;">Privacy Policy</a>
                            </p>
                        </div>
                    </div>

                    <!-- ✅ Sign-up Form -->
                    <div class="bg-white w-full md:w-3/5 mx-auto p-5 mt-4 rounded shadow-lg">
                        <label class="block font-medium text-gray-800 mb-1">Events Location</label>
                        <select v-model="selectedLocation" class="form-select mb-3 w-full border rounded px-3 py-2">
                            <option value="" disabled>Select a location</option>

                            <template v-for="(group, date) in groupedLocations" :key="date">
                                <optgroup :label="formatDate(date)">
                                    <option v-for="location in group" :key="location.id" :value="location.id">
                                        {{ location.name }} - {{ location.formatted_time }}
                                    </option>
                                </optgroup>
                            </template>
                        </select>
                        <!-- ✅ Loop through questions -->
                        <div v-for="(question, index) in JSON.parse(signupForm.questions || '[]')" :key="index" class="mb-4">
                            <label class="block font-medium text-gray-800 mb-1">{{ question.text }}</label>

                            <template v-if="question.type === 'text'">
                                <input type="text" class="w-full border rounded px-3 py-2" disabled />
                            </template>
                            
                            <template v-if="question.type === 'number'">
                                <input type="number" class="w-full border rounded px-3 py-2" disabled />
                            </template>

                            <template v-if="question.type === 'email'">
                                <input type="email" class="w-full border rounded px-3 py-2" disabled />
                            </template>

                            <template v-if="question.type === 'dropdown'">
                                <select class="w-full border rounded px-3 py-2">
                                    <option v-for="option in question.options" :key="option">{{ option }}</option>
                                </select>
                            </template>
                        </div>

                        <!-- ✅ Marketing Permission Checkbox (Required) -->
                        <div class="form-check mt-3">
                            <input type="checkbox" class="form-check-input" id="marketingPermission" required>
                            <label class="form-check-label" for="marketingPermission">
                                <strong>Marketing Permission</strong> <br>
                                By checking the box, you accept the competition terms and conditions and consent to receive marketing materials related to the offerings of Adventure Entertainment and our partners.
                            </label>
                        </div>

                        <!-- ✅ Submit Button -->
                        <div class="flex justify-center mt-5">
                            <button class="bg-blue-500 text-white px-6 py-2 rounded w-full md:w-auto" @click="submitTest()">Submit</button>
                        </div>
                    </div>
                </div>

               <!-- ✅ If no signup form exists, show a message -->
                <div v-if="!signupForm" class="text-center text-gray-600 mt-5">
                    <!-- ✅ Admin sees this message and can generate a form -->
                    <p v-if="userRole === 'admin'">
                        No signup form created yet. Click <strong>"Create Sign Up Form"</strong> to create one.
                    </p>

                    <!-- ✅ Host sees this message but cannot generate -->
                    <p v-else-if="userRole === 'host'">
                        No signup form created yet. Please contact the Admin to create the form for you.
                    </p>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>