<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';

const props = defineProps({
    form: Object,
    event: Object,
});

// Store form values
const formValues = ref({});
const isSubmitted = ref(false);

// ✅ Get CSRF token from Laravel
const csrfToken = computed(() => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
});

// Handle form submission
const submitForm = () => {
    router.post(route('signup.storeEmbedded', { eventId: props.event.id }), { 
        ...formValues.value,
        _token: csrfToken.value  // ✅ Include CSRF token in the request
    }, {
        onSuccess: () => {
            Swal.fire('Success!', 'Your sign-up has been submitted.', 'success');
            formValues.value = {}; // Clear form after submission
            isSubmitted.value = true; // ✅ Show thank-you card
        },
        onError: (errors) => {
            Swal.fire('Error!', 'Please fill all required fields.', 'error');
            console.log(errors);
        }
    });
};
</script>

<template>
    <div class="container mt-4 mb-4 d-flex justify-content-center align-items-center flex-column" style="min-height: 100vh;">
        
        <!-- Form Container -->
        <div class="text-center mb-4 border rounded shadow-sm bg-light col-12 col-md-8 col-lg-5">
            <div class="w-100">
                <img :src="'/storage/' + event.event_banner" alt="Event Banner" 
                     class="img-fluid w-100" 
                     style="object-fit: cover; height: 250px;">
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
                    <a :href="form.terms_link" target="_blank" class="text-decoration-none">Terms and Conditions</a> |
                    <a :href="form.privacy_link" target="_blank" class="text-decoration-none">Privacy Policy</a>
                </p>
            </div>
        </div>

        <!-- ✅ Sign-up Form (Hidden after submission) -->
        <form @submit.prevent="submitForm" 
              class="p-3 border rounded shadow-sm bg-light col-12 col-md-8 col-lg-5" 
              v-if="!isSubmitted">
            <input type="hidden" :value="csrfToken" name="_token">

            <div v-for="(question, index) in JSON.parse(form.questions)" :key="index" class="mb-3">
                <label class="form-label">{{ question.text }}</label>

                <template v-if="question.type === 'text' || question.type === 'number'">
                    <input v-model="formValues[question.text]" 
                           :type="question.type" 
                           class="form-control" required>
                </template>

                <template v-else-if="question.type === 'dropdown'">
                    <select v-model="formValues[question.text]" class="form-select" required>
                        <option value="">Select an option</option>
                        <option v-for="option in question.options" :key="option" :value="option">
                            {{ option }}
                        </option>
                    </select>
                </template>
            </div>

            <div class="d-flex justify-content-center mt-4 mb-3">
                <button type="submit" class="btn btn-primary w-40">Submit</button>
            </div>
        </form>
    </div>
</template>
