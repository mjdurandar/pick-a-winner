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
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Sign Up Form for Event: {{ eventValues.event_name }}
                </h2>
                <div>
                <button v-if="signupForm" @click="copySignupFormUrl()" class="btn btn-success me-2">
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

        <div class="p-5">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- ✅ If a signup form exists, display it -->
                <div v-if="signupForm">
                    <div class="m-auto mb-3 text-center" style="background-color: black; width: 60%; color: azure;">
                        <img style="height: 400px;" :src="'/storage/' + eventValues.event_banner" class="card-img-top" alt="Event Banner" />
                    </div>
                    <div class="pt-4 pb-5 pe-5 ps-5 m-auto" style="background-color: white; width: 60%;">
                        <!-- ✅ Form description -->
                        <div class="mb-3 text-center">
                            <h1 class="mb-3" style="font-size: 22px; font-weight: 900; margin-bottom: 10px;">
                                {{ form.heading }}
                            </h1>
                            <p class="mb-3" style="font-size: 16px;">
                                {{ form.event_description }}
                            </p>
                            <p> 
                                <a :href="form.terms_link" target="_blank" class="text-blue-600 underline">Terms and Conditions</a> | 
                                <a :href="form.privacy_link" target="_blank" class="text-blue-600 underline">Privacy Policy</a>
                            </p>
                        </div>

                        <!-- ✅ Loop through questions -->
                        <div v-for="(question, index) in JSON.parse(signupForm.questions || '[]')" :key="index" class="mb-3">
                            <label class="form-label">{{ question.text }}</label>
                            
                            <template v-if="question.type === 'text' || question.type === 'number'">
                                <input type="text" class="form-control" disabled />
                            </template>

                            <template v-if="question.type === 'dropdown'">
                                <select class="form-select">
                                    <option v-for="option in question.options" :key="option">{{ option }}</option>
                                </select>
                            </template>
                        </div>
                        <div class="d-flex justify-content-center mt-5">
                            <button class="btn btn-primary">Submit</button>
                        </div>
                    </div>
                </div>

                <!-- ✅ If no signup form exists, show a message -->
                <div v-else>
                    <p>No signup form generated yet. Click "Generate Sign Up Form" to create one.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
