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

const marketingPermission = ref(false); // ✅ Track checkbox state

// ✅ Get CSRF token from Laravel
const csrfToken = computed(() => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
});

// Handle form submission
const submitForm = () => {
    // ✅ Validate if the checkbox is checked
    if (!marketingPermission.value) {
        Swal.fire('Error!', 'You must accept the Marketing Permission terms.', 'error');
        return;
    }
    router.post(route('signup.storeEmbedded', { eventId: props.event.id }), { 
        ...formValues.value,
        marketing_permission: marketingPermission.value, // ✅ Include checkbox value in the request
        _token: csrfToken.value  // ✅ Include CSRF token in the request
    }, {
        onSuccess: () => {
            Swal.fire('Success!', 'Your sign-up has been submitted.', 'success');
            formValues.value = {}; // Clear form after submission
            marketingPermission.value = false; // Reset checkbox
            isSubmitted.value = true; // ✅ Show thank-you card
        },
        onError: (errors) => {
            Swal.fire('Error!', 'Please fill all required fields.', 'error');
            console.log(errors);
        }
    });
};

const formatPhoneNumber = (index, fieldName, format) => {
    let rawValue = formValues.value[fieldName];

    if (!rawValue || !format) return;

    // Remove all non-numeric characters
    rawValue = rawValue.replace(/\D/g, '');

    // Apply format dynamically
    let formattedNumber = '';
    let rawIndex = 0;

    for (let char of format) {
        if (char === '#') {
            if (rawValue[rawIndex]) {
                formattedNumber += rawValue[rawIndex];
                rawIndex++;
            }
        } else {
            formattedNumber += char;
        }
    }

    formValues.value[fieldName] = formattedNumber;
};

</script>

<template>
    <div class="container mt-4 mb-4 d-flex justify-content-center align-items-center flex-column" style="min-height: 100vh;">
        
        <!-- Form Container -->
        <div class="text-center mb-4 border rounded shadow-sm bg-light col-12 col-md-8 col-lg-5">
            <div class="w-100">
                <img :src="'/storage/' + event.event_banner" alt="Event Banner" 
                     class="img-fluid w-100" 
                     style="object-fit: cover; height: 100%;">
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

            <template v-for="(question, index) in JSON.parse(form.questions)" :key="index">
                <label class="form-label">{{ question.text }}</label>

                <!-- ✅ Text & Number Inputs -->
                <template v-if="question.type === 'text'">
                    <div class="pb-3">
                        <input v-model="formValues[question.text]" type="text" class="form-control" required>
                    </div>
                </template>

                <template v-if="question.type === 'email'">
                    <div class="pb-3">
                        <input v-model="formValues[question.text]" type="email" class="form-control" required>
                    </div>
                </template>

                <template v-if="question.type === 'number'">
                    <div class="pb-3">
                        <input 
                        v-model="formValues[question.text]" 
                        :type="question.format === 'FREE-NUMERIC' ? 'number' : 'text'" 
                        class="form-control"
                        :placeholder="question.format === 'FREE-NUMERIC' ? 'Enter number' : question.format"
                        required
                        @input="formatPhoneNumber(index, question.text, question.format)">
                    </div>
                </template>

                <!-- ✅ Dropdowns -->
                <template v-else-if="question.type === 'dropdown'">
                    <div class="pb-3">
                        <select v-model="formValues[question.text]" class="form-select" required>
                        <option value="">Select an option</option>
                        <option v-for="option in question.options" :key="option" :value="option">{{ option }}</option>
                    </select>
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
