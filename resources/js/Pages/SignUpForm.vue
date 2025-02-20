<script setup>
import { ref, watchEffect, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// ✅ Receive `eventId` and `form` as props
const props = defineProps({ 
    eventId: String, 
    eventValues: Object,
    form: Object
});

// ✅ Store form data reactively
const signupForm = ref(props.form || null);
// ✅ Generate Signup Form Link
const signupFormUrl = computed(() => {
    return `${window.location.origin}/adventureentertainment/form/${props.eventId}`;
});


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

const generateSignUpForm = () => {
    Swal.fire({
        title: 'Are you sure you want to Generate Sign Up Form?',
        text: 'This action will create default questions for this event!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, generate it!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            router.post(route('signup.generate'), { event_id: props.eventId }, {
                onSuccess: (response) => {
                    Swal.fire('Generated!', 'Sign Up Form has been created.', 'success');
                    
                    // ✅ Update form data when the response is received
                    signupForm.value = response.props.form;
                }
            });
        }
    });
};

// ✅ Redirect to edit form page
const editSignUpForm = () => {
    if (signupForm.value) {
        router.get(route('signup.edit', { formId: signupForm.value.id }));
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
                <div>
                    <button v-if="signupForm" @click="copySignupFormUrl()" class="btn btn-success me-3">
                        Copy Signup Link
                    </button>

                    <button v-if="signupForm" @click="editSignUpForm()" class="btn btn-warning">
                        Edit Form
                    </button>
                    
                    <button v-else @click="generateSignUpForm()" class="btn btn-primary">
                        Generate Sign Up Form
                    </button>
                </div>
            </div>
        </template>

        <div class="container mt-4 mb-4 d-flex justify-content-center align-items-center flex-column" style="min-height: 100vh;">
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
                                <a :href="form.terms_link" target="_blank" class="text-decoration-none">Terms and Conditions</a> |
                                <a :href="form.privacy_link" target="_blank" class="text-decoration-none">Privacy Policy</a>
                            </p>
                        </div>
                    </div>

                    <!-- ✅ Sign-up Form -->
                    <div class="bg-white w-full md:w-3/5 mx-auto p-5 mt-4 rounded shadow-lg">
                        <!-- ✅ Loop through questions -->
                        <div v-for="(question, index) in JSON.parse(signupForm.questions || '[]')" :key="index" class="mb-4">
                            <label class="block font-medium text-gray-800 mb-1">{{ question.text }}</label>

                            <template v-if="question.type === 'text' || question.type === 'number'">
                                <input type="text" class="w-full border rounded px-3 py-2" disabled />
                            </template>

                            <template v-if="question.type === 'dropdown'">
                                <select class="w-full border rounded px-3 py-2">
                                    <option v-for="option in question.options" :key="option">{{ option }}</option>
                                </select>
                            </template>
                        </div>

                        <!-- ✅ Submit Button -->
                        <div class="flex justify-center mt-5">
                            <button class="bg-blue-500 text-white px-6 py-2 rounded w-full md:w-auto" @click="submitTest()">Submit</button>
                        </div>
                    </div>
                </div>

                <!-- ✅ If no signup form exists, show a message -->
                <div v-else class="text-center text-gray-600 mt-5">
                    <p>No signup form generated yet. Click "Generate Sign Up Form" to create one.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

