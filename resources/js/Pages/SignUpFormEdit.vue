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

// ✅ Dragging logic for questions (description fields remain static)
const draggedIndex = ref(null);

const dragStart = (index) => {
    draggedIndex.value = index;
};

const drop = (index) => {
    if (draggedIndex.value !== null) {
        // Move the dragged question to the new position
        const movedQuestion = signupForm.value.questions.splice(draggedIndex.value, 1)[0];
        signupForm.value.questions.splice(index, 0, movedQuestion);
        draggedIndex.value = null;
    }
};

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
    // ✅ Check if required fields are filled
    if (!signupForm.value.heading.trim() || 
        !signupForm.value.event_description.trim() || 
        !signupForm.value.privacy_link.trim() || 
        !signupForm.value.terms_link.trim()) {
        Swal.fire('Error!', 'Please fill in all required fields.', 'error');
        return;
    }

    // ✅ Check if at least one question exists
    if (signupForm.value.questions.length === 0) {
        Swal.fire('Error!', 'At least one question is required.', 'error');
        return;
    }

    // ✅ Check if all questions have text and type
    for (let i = 0; i < signupForm.value.questions.length; i++) {
        let question = signupForm.value.questions[i];

        if (!question.text.trim()) {
            Swal.fire('Error!', `Question ${i + 1} is missing text.`, 'error');
            return;
        }

        if (!question.type) {
            Swal.fire('Error!', `Please select a type for Question ${i + 1}.`, 'error');
            return;
        }

        // ✅ Check if dropdown questions have at least one option
        if (question.type === 'dropdown' && question.options.length === 0) {
            Swal.fire('Error!', `Dropdown question ${i + 1} must have at least one option.`, 'error');
            return;
        }
    }

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
                heading: signupForm.value.heading,
                event_description: signupForm.value.event_description,
                privacy_link: signupForm.value.privacy_link,
                terms_link: signupForm.value.terms_link,
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

        <!-- Responsive Form Container -->
        <div class="py-6 mx-auto w-full px-4 md:w-1/2">
            <div class="bg-white p-6 shadow rounded-lg">

                <!-- ✅ Heading Input -->
                <h2 class="text-lg font-bold mb-3">Edit Heading</h2>
                <div class="mb-4">
                    <input v-model="signupForm.heading" type="text" class="w-full border p-2 rounded" />
                </div>

                <!-- ✅ Description Input -->
                <h2 class="text-lg font-bold mb-3">Edit Description</h2>
                <div class="mb-4">
                    <textarea v-model="signupForm.event_description" class="w-full border p-2 rounded" rows="3"></textarea>
                </div>

                <!-- ✅ Privacy Policy Link -->
                <h2 class="text-lg font-bold mb-3">Edit Privacy Policy Link</h2>
                <div class="mb-4">
                    <input v-model="signupForm.privacy_link" type="text" class="w-full border p-2 rounded" />
                </div>

                <!-- ✅ Terms and Conditions Link -->
                <h2 class="text-lg font-bold mb-3">Edit Terms and Conditions Link</h2>
                <div class="mb-4">
                    <input v-model="signupForm.terms_link" type="text" class="w-full border p-2 rounded" />
                </div>

                <!-- ✅ Draggable Questions Section -->
                <h2 class="text-lg font-bold mb-3">Edit Questions (Drag to Reorder)</h2>
                <div>
                    <div
                        v-for="(question, index) in signupForm.questions"
                        :key="index"
                        draggable="true"
                        @dragstart="dragStart(index)"
                        @dragover.prevent
                        @drop="drop(index)"
                        class="mb-4 p-3 border rounded shadow-sm bg-gray-100 cursor-grab"
                    >
                        <label class="font-medium">Question:</label>
                        <input v-model="question.text" type="text" class="w-full border p-2 rounded mb-2" />

                        <!-- ✅ Question Type Dropdown -->
                        <select v-model="question.type" @change="updateQuestionType(index, question.type)" class="w-full border p-2 rounded mb-2">
                            <option value="text">Text Input</option>
                            <option value="dropdown">Dropdown</option>
                        </select>

                        <!-- ✅ Dropdown Options -->
                        <div v-if="question.type === 'dropdown'">
                            <label class="font-medium">Dropdown Options:</label>
                            <div v-for="(option, optIndex) in question.options" :key="optIndex" class="flex gap-2 mb-2">
                                <input v-model="question.options[optIndex]" type="text" class="flex-1 border p-2 rounded" />
                                <button @click="removeOption(index, optIndex)" class="bg-red-500 text-white px-2 py-1 rounded">Remove</button>
                            </div>
                            <button @click="addOption(index)" class="bg-green-500 text-white px-3 py-1 rounded">Add Option</button>
                        </div>

                        <!-- ✅ Remove Question Button -->
                        <button @click="removeQuestion(index)" class="bg-red-500 text-white px-3 py-1 rounded mt-2">Remove Question</button>
                    </div>
                </div>

                <!-- ✅ Add Question Button -->
                <button @click="addQuestion" class="bg-blue-500 text-white px-3 py-2 rounded w-full mt-3">Add Question</button>

                <!-- ✅ Save Form Button -->
                <button @click="saveForm" class="bg-green-500 text-white px-4 py-2 rounded w-full mt-4">Save Form</button>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

