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

// ✅ Dragging logic for questions
const draggedQuestionIndex = ref(null);
const dragStartQuestion = (index) => {
    draggedQuestionIndex.value = index;
};

const dropQuestion = (index) => {
    if (draggedQuestionIndex.value !== null) {
        const movedQuestion = signupForm.value.questions.splice(draggedQuestionIndex.value, 1)[0];
        signupForm.value.questions.splice(index, 0, movedQuestion);
        draggedQuestionIndex.value = null;
    }
};

// ✅ Dragging logic for dropdown options
const draggedOptionIndex = ref(null);
const draggedQuestionForOption = ref(null);

const dragStartOption = (qIndex, optIndex) => {
    draggedOptionIndex.value = optIndex;
    draggedQuestionForOption.value = qIndex;
};

const dropOption = (qIndex, optIndex) => {
    if (draggedOptionIndex.value !== null && draggedQuestionForOption.value === qIndex) {
        const movedOption = signupForm.value.questions[qIndex].options.splice(draggedOptionIndex.value, 1)[0];
        signupForm.value.questions[qIndex].options.splice(optIndex, 0, movedOption);
        draggedOptionIndex.value = null;
        draggedQuestionForOption.value = null;
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
    let question = signupForm.value.questions[qIndex];
    // ✅ Check if the question is related to location
    if (question.text.toLowerCase().includes("location")) {
        Swal.fire({
            title: "Are you sure?",
            text: "Removing this location will also remove the Win Sheet Data. Please download the backup. Proceed?",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Yes, remove it!",
            cancelButtonText: "No, cancel",
        }).then((result) => {
            if (result.isConfirmed) {
                signupForm.value.questions[qIndex].options.splice(optIndex, 1);
                Swal.fire("Removed!", "The location has been removed.", "success");
            }
        });
    } else {
        signupForm.value.questions[qIndex].options.splice(optIndex, 1);
    }
};

// ✅ Save the updated form
const saveForm = () => {
    if (!signupForm.value.heading.trim() || 
        !signupForm.value.event_description.trim() || 
        !signupForm.value.privacy_link.trim() || 
        !signupForm.value.terms_link.trim()) {
        Swal.fire('Error!', 'Please fill in all required fields.', 'error');
        return;
    }

    if (signupForm.value.questions.length === 0) {
        Swal.fire('Error!', 'At least one question is required.', 'error');
        return;
    }

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

        if (question.type === 'dropdown') {
            if (question.options.length === 0) {
                Swal.fire('Error!', `Dropdown question ${i + 1} must have at least one option.`, 'error');
                return;
            }

            // ✅ Check if any dropdown option is empty
            for (let j = 0; j < question.options.length; j++) {
                if (!question.options[j].trim()) {
                    Swal.fire('Error!', `Dropdown question ${i + 1} has an empty option. Please remove it or fill it in.`, 'error');
                    return;
                }
            }
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
                questions: JSON.stringify(signupForm.value.questions)
            }, {
                onSuccess: () => {
                    Swal.fire('Saved!', 'Sign Up Form has been updated.', 'success');
                    router.get(route('signup.index', { eventId: signupForm.value.event_id }));
                }
            });
        }
    });
};

// ✅ Watch for changes in `props.form` and update fields
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

        <div class="py-6 mx-auto w-full px-4 md:w-1/2">
            <div class="bg-white p-6 shadow rounded-lg">

                <!-- ✅ Draggable Questions Section -->
                <h2 class="text-lg font-bold mb-3">Edit Questions (Drag to Reorder)</h2>
                <div>
                    <div
                        v-for="(question, index) in signupForm.questions"
                        :key="index"
                        draggable="true"
                        @dragstart="dragStartQuestion(index)"
                        @dragover.prevent
                        @drop="dropQuestion(index)"
                        class="mb-4 p-3 border rounded shadow-sm bg-gray-100 cursor-grab"
                    >
                        <label class="font-medium">Question:</label>
                        <input v-model="question.text" type="text" class="w-full border p-2 rounded mb-2" />

                        <select v-model="question.type" @change="updateQuestionType(index, question.type)" class="w-full border p-2 rounded mb-2">
                            <option value="text">Text Input</option>
                            <option value="dropdown">Dropdown</option>
                        </select>

                        <!-- ✅ Draggable Dropdown Options -->
                        <div v-if="question.type === 'dropdown'">
                            <label class="font-medium">Dropdown Options (Drag to Reorder):</label>
                            <div
                                v-for="(option, optIndex) in question.options"
                                :key="optIndex"
                                draggable="true"
                                @dragstart="dragStartOption(index, optIndex)"
                                @dragover.prevent
                                @drop="dropOption(index, optIndex)"
                                class="flex gap-2 mb-2 p-2 border rounded cursor-grab bg-gray-200"
                            >
                                <input v-model="question.options[optIndex]" type="text" class="flex-1 border p-2 rounded" />
                                <button @click="addOption(index)" class="bg-green-500 text-white px-3 py-2 rounded">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                                <button @click="removeOption(index, optIndex)" class="bg-red-500 text-white px-3 py-1 rounded">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>

                        <button @click="removeQuestion(index)" class="bg-red-500 text-white px-3 py-1 rounded mt-2">Remove Question</button>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button @click="addQuestion" class="bg-blue-500 text-white px-3 py-2 rounded">Add Question</button>
                    <button @click="saveForm" class="bg-green-500 text-white px-4 py-2 rounded">Save Form</button>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

