<script setup>
import { ref, watchEffect } from 'vue';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// ✅ Receive `form` and `events` as props
const props = defineProps({ 
    form: Object,
    events: Object,
});

// ✅ Create a **reactive** copy of form (instead of modifying props directly)
const signupForm = ref(props.form ? { 
    ...props.form, 
    questions: JSON.parse(props.form.questions) 
} : null);

// ✅ Add a new question
const addQuestion = () => {
    signupForm.value.questions.push({ text: '', type: 'text', options: [] });
};

// ✅ Remove a question
const removeQuestion = (index) => {
    signupForm.value.questions.splice(index, 1);
};

// ✅ Change question type (text or dropdown)
const updateQuestionType = (index, type) => {
    signupForm.value.questions[index].type = type;
    if (type === 'dropdown') {
        signupForm.value.questions[index].options = ['Option 1', 'Option 2']; // Default dropdown options
    } else {
        signupForm.value.questions[index].options = [];
    }
};

// ✅ Add a dropdown option
const addOption = (index) => {
    signupForm.value.questions[index].options.push('');
};

// ✅ Remove a dropdown option
const removeOption = (qIndex, optIndex) => {
    signupForm.value.questions[qIndex].options.splice(optIndex, 1);
};

// ✅ Save the updated form
const saveForm = () => {
    Swal.fire({
        title: 'Save changes?',
        text: 'This will update the sign-up form.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, save it!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            router.post(route('signup.update', { eventId: signupForm.value.event_id }), {
                heading: signupForm.value.heading, // ✅ Now correctly referencing the reactive object
                event_description: signupForm.value.event_description, // ✅ Now correctly referencing the reactive object
                privacy_link: signupForm.value.privacy_link, // ✅ Now correctly referencing the reactive object
                terms_link: signupForm.value.terms_link, // ✅ Now correctly referencing the reactive object
                questions: JSON.stringify(signupForm.value.questions) // ✅ Ensure questions are saved properly
            }, {
                onSuccess: () => {
                    Swal.fire('Saved!', 'Sign Up Form has been updated.', 'success');
                    router.get(route('signup.index', { eventId: signupForm.value.event_id })); // ✅ Redirect back to form view
                }
            });
        }
    });
};

// ✅ Watch for changes in `props.form` and update all fields in `signupForm`
watchEffect(() => {
    if (props.form) {
        signupForm.value = { 
            ...props.form, 
            questions: JSON.parse(props.form.questions) 
        };
    }
});
</script>


<template>
    <Head title="Edit Sign Up Form" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Edit Sign Up Form for Event: {{ events.event_name }}
            </h2>
        </template>

        <div class="py-6 m-auto" style="width: 50%;">
            <div class="mx-auto max-w-5xl bg-white p-6 shadow rounded-lg">
                <h2 class="text-lg font-bold mb-3">Edit Heading</h2>
                <div class="mb-4 p-3 border rounded">
                    <input v-model="form.heading" type="text" class="form-control mb-2" />
                </div>
                <h2 class="text-lg font-bold mb-3">Edit Description</h2>
                <div class="mb-4 p-3 border rounded">
                    <input v-model="form.event_description" type="text" class="form-control mb-2" />
                </div>
                <h2 class="text-lg font-bold mb-3">Edit Description</h2>
                <div class="mb-4 p-3 border rounded">
                    <input v-model="form.event_description" type="text" class="form-control mb-2" />
                </div>
                <h2 class="text-lg font-bold mb-3">Edit Privacy Policy Link</h2>
                <div class="mb-4 p-3 border rounded">
                    <input v-model="form.privacy_link" type="text" class="form-control mb-2" />
                </div>
                <h2 class="text-lg font-bold mb-3">Edit Terms and Conditions Link</h2>
                <div class="mb-4 p-3 border rounded">
                    <input v-model="form.terms_link" type="text" class="form-control mb-2" />
                </div>
                
                <h2 class="text-lg font-bold mb-3">Edit Questionss</h2>

                <!-- ✅ Loop through questions -->
                <div v-for="(question, index) in signupForm.questions" :key="index" class="mb-4 p-3 border rounded">
                    <label class="form-label font-medium">Question:</label>
                    <input v-model="question.text" type="text" class="form-control mb-2" />

                    <!-- ✅ Question Type Dropdown -->
                    <select v-model="question.type" @change="updateQuestionType(index, question.type)" class="form-select mb-2">
                        <option value="text">Text Input</option>
                        <option value="dropdown">Dropdown</option>
                    </select>

                    <!-- ✅ Dropdown Options -->
                    <div v-if="question.type === 'dropdown'">
                        <label class="form-label">Dropdown Options:</label>
                        <div v-for="(option, optIndex) in question.options" :key="optIndex" class="d-flex mb-2">
                            <input v-model="question.options[optIndex]" type="text" class="form-control me-2" />
                            <button @click="removeOption(index, optIndex)" class="btn btn-danger btn-sm">Remove</button>
                        </div>
                        <button @click="addOption(index)" class="btn btn-success btn-sm m-1">Add Option</button>
                    </div>

                    <!-- ✅ Remove Question Button -->
                    <button @click="removeQuestion(index)" class="btn btn-danger btn-sm mt-2">Remove Question</button>
                </div>

                <!-- ✅ Add Question Button -->
                <button @click="addQuestion" class="btn btn-primary btn-sm mt-3">Add Question</button>

                <!-- ✅ Save Form Button -->
                <button @click="saveForm" class="btn btn-success w-full mt-4">Save Form</button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
