<script setup>
import { ref, watch, watchEffect, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// ✅ Receive props
const props = defineProps({ 
    events: Object,
    form: Object, // 👈 Form data (for editing)
    locations: Array, // Add locations prop
});

// ✅ Reactive form fields
const headerText = ref(props.form.heading);
const descriptionText = ref(props.form.event_description);
const termsLink = ref(props.form.terms_link);
const policyLink = ref(props.form.privacy_link);

// ✅ Convert existing questions into reactive state
const questions = ref(JSON.parse(props.form.questions || '[]')); 
questions.value.forEach((question) => {
    question.isNew = false; // Mark all existing questions as not new
    if (question.column_name === "events_location" && !question.hiddenOptions) {
        question.hiddenOptions = []; // ✅ Ensure hiddenOptions exists
    }
 
    if (question.type === 'dropdown' && question.hasOtherOption === undefined) {
        question.hasOtherOption = false;
    }

    if (question.allowMultiple === undefined) {
        question.allowMultiple = false;
    }
});

const ADDRESS_COLUMNS = ['street_address','street_address_2','city','state','zip_code','country'];
const ADDRESS_QUESTIONS_TEMPLATE = [
  { text: 'Street Address', type: 'text', column_name: 'street_address', options: [], hasOtherOption: false, allowMultiple: false, isNew: true },
  { text: 'Address Line 2', type: 'text', column_name: 'street_address_2', options: [], hasOtherOption: false, allowMultiple: false, isNew: true },
  { text: 'City', type: 'text', column_name: 'city', options: [], hasOtherOption: false, allowMultiple: false, isNew: true },
  { text: 'State', type: 'text', column_name: 'state', options: [], hasOtherOption: false, allowMultiple: false, isNew: true },
  { text: 'Zip Code', type: 'text', column_name: 'zip_code', options: [], hasOtherOption: false, allowMultiple: false, isNew: true },
  { text: 'Country', type: 'dropdown', column_name: 'country', options: ['Australia', 'New Zealand', 'USA', 'Canada', 'Germany', 'United Kingdom', 'Europe'], hasOtherOption: false, allowMultiple: false, isNew: true },
];

// Default toggle based on whether any address column exists
const collectAddress = ref(questions.value.some(q => ADDRESS_COLUMNS.includes(q.column_name)));

const removeAddressQuestions = () => {
  questions.value = questions.value.filter(q => !ADDRESS_COLUMNS.includes(q.column_name));
};

const addAddressQuestionsIfMissing = () => {
  const afterIndex = questions.value.findIndex(q => q.column_name === 'last_name');
  const existing = new Set(questions.value.map(q => q.column_name));
  const toInsert = ADDRESS_QUESTIONS_TEMPLATE.filter(q => !existing.has(q.column_name));
  if (toInsert.length === 0) return;
  if (afterIndex !== -1) {
    questions.value.splice(afterIndex + 1, 0, ...toInsert);
  } else {
    questions.value.push(...toInsert);
  }
};

watch(collectAddress, (shouldCollect) => {
  if (shouldCollect) {
    addAddressQuestionsIfMissing();
  } else {
    removeAddressQuestions();
  }
});

// ✅ Dragging logic
const draggedQuestionIndex = ref(null);
const dragStart = (index) => {
    draggedQuestionIndex.value = index;
};
const drop = (index) => {
    if (draggedQuestionIndex.value !== null) {
        const movedQuestion = questions.value.splice(draggedQuestionIndex.value, 1)[0];
        questions.value.splice(index, 0, movedQuestion);
        draggedQuestionIndex.value = null;
    }
};

// Format locations to display date and time in the desired format
const formattedLocations = computed(() => {
    if (!props.locations || !Array.isArray(props.locations)) return [];
    
    return props.locations.map(location => {
        return {
            ...location,
            formattedDate: formatDate(location.date),
            formattedTime: formatTime(location.time)
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
            return timeString.replace(' ', ''); // Remove space between time and AM/PM
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

const toggleHiddenOption = (question, option) => {
    if (!question.hiddenOptions) {
        question.hiddenOptions = []; // ✅ Ensure it's initialized
    }

    const index = question.hiddenOptions.indexOf(option);
    if (index === -1) {
        // ✅ Add to hiddenOptions
        question.hiddenOptions.push(option);
    } else {
        // ✅ Remove from hiddenOptions
        question.hiddenOptions.splice(index, 1);
    }
};

// Track the dragged option index
const draggedOptionIndex = ref(null);

// ✅ Start Dragging a Dropdown Option
const dragStartOption = (question, optIndex) => {
    draggedOptionIndex.value = optIndex;
};

// ✅ Drop the Dropdown Option at New Position
const dropOption = (question, optIndex) => {
    if (draggedOptionIndex.value !== null) {
        const movedOption = question.options.splice(draggedOptionIndex.value, 1)[0];
        question.options.splice(optIndex, 0, movedOption);
        draggedOptionIndex.value = null;
    }
};

// ✅ Add a new question (user must provide column name)
const addQuestion = () => {
    questions.value.push({
        text: '',
        type: 'text',
        column_name: '', // 👈 Users must provide a column name
        options: ['Option 1', 'Option 2'],
        hasOtherOption: false,
        allowMultiple: false,
        isNew: true // Add this flag for new questions
    });
};

// ✅ Remove a question
const removeQuestion = (index) => {
    Swal.fire({
        title: 'Remove Question?',
        text: 'Are you sure you want to remove this question?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, remove it!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            questions.value.splice(index, 1);
        }
    });
};

const removeDropdownOption = (question, optIndex) => {
    if(question.column_name === 'events_location'){
        const optionValue = question.options[optIndex]?.trim();

        // ✅ If the option is empty, remove it immediately
        if (!optionValue) {
            question.options.splice(optIndex, 1);
            return;
        }

        // ✅ If the option has a value, show a confirmation before removing
        Swal.fire({
            title: "Remove Location?",
            text: "Are you sure you want to remove this option? The Win Sheet connected to this location will also be removed.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Yes, remove it!",
            cancelButtonText: "No, cancel"
        }).then((result) => {
            if (result.isConfirmed) {
                question.options.splice(optIndex, 1);
            }
        });
    }
    else{
        question.options.splice(optIndex, 1);
    }
};

const showModal = ref(false); // Controls the visibility of the modal
const selectedLocation = ref(''); // Define the reactive variable for the selected location

const selectLocation = (locationName) => {
    selectedLocation.value = locationName; // Update the selected location
    showModal.value = false; // Close the modal
};

// ✅ Save the form (update)
const saveForm = () => {
    questions.value.forEach(question => {
        // ✅ Ensure hiddenOptions exists
        if (question.column_name === "events_location" && !question.hiddenOptions) {
            question.hiddenOptions = [];
        }
    });
   //Validate if all dropdown got a value
   for (let question of questions.value) {
        // ✅ Validate Dropdown Options
        if (question.type === 'dropdown') {
            for (let option of question.options) {
                if (!option.trim()) {
                    Swal.fire('Error!', 'All dropdown options must have a value.', 'error');
                    return;
                }
            }
        }

        // ✅ Validate Column Name
        if (!question.column_name.trim()) {
            Swal.fire('Error!', 'Column name must not be empty.', 'error');
            return;
        }

        // ✅ Validate Column Name
        if (!question.text.trim()) {
            Swal.fire('Error!', 'Question must not be empty.', 'error');
            return;
        }
    }

    Swal.fire({
        title: 'Save changes?',
        text: 'This will update the sign-up form.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, update it!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            router.post(route('signup.update', { eventId: props.events.id }), { 
                heading: headerText.value,
                event_description: descriptionText.value,
                terms_link: termsLink.value,
                privacy_link: policyLink.value,
                questions: questions.value
            }, {
                onSuccess: () => {
                    Swal.fire('Saved!', 'Sign Up Form has been updated.', 'success');
                    router.get(route('signup.index', { eventId: props.events.id }));
                }
            });
        }
    });
};
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
                <h2 class="text-lg font-bold mb-3">Edit Header Text</h2>
                <label class="font-medium">Header Text:</label>
                <input v-model="headerText" type="text" class="w-full border p-2 rounded mb-2" />

                <label class="font-medium">Description Text:</label>
                <textarea v-model="descriptionText" class="w-full border p-2 rounded mb-2 h-32"></textarea>

                <label class="font-medium">Terms and Condition Link:</label>
                <input v-model="termsLink" type="text" class="w-full border p-2 rounded mb-2" />

                <label class="font-medium">Privacy Policy Link:</label>
                <input v-model="policyLink" type="text" class="w-full border p-2 rounded mb-2" />
            </div>
        </div>

        <div class="pb-5 mx-auto w-full px-4 md:w-1/2">
            <div class="bg-white p-6 shadow rounded-lg">
                <h2 class="text-lg font-bold mb-3">Edit Questions (Drag to Reorder)</h2>
                <div class="mb-4 p-3 border rounded shadow-sm bg-gray-50">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" v-model="collectAddress" />
                        <span class="font-medium">Collect address details</span>
                    </label>
                </div>
                <div class="mb-4 p-3 border rounded shadow-sm bg-gray-100">
                    <button 
                        @click="showModal = true" 
                        class="bg-blue-500 text-white px-4 py-2 rounded"
                    >
                        View Locations
                    </button>
                </div>
                <div>
                    <div
                        v-for="(question, index) in questions"
                        :key="index"
                        draggable="true"
                        @dragstart="dragStart(index)"
                        @dragover.prevent
                        @drop="drop(index)"
                        class="mb-4 p-3 border rounded shadow-sm bg-gray-100 cursor-grab"
                    >
                        <label class="font-medium">Question:</label> 
                        <label class="font-medium m-2 text-red-500" 
                        v-if="question.column_name == 'events_location' || question.column_name == 'email_address'
                        || question.column_name == 'first_name' || question.column_name == 'last_name'
                        || question.column_name == 'mobile_number' || question.column_name == 'age'
                        || question.column_name == 'gender'">REQUIRED</label>
                        <input v-model="question.text" type="text" class="w-full border p-2 rounded mb-2" />

                        <label class="font-medium">Column Name:</label>
                        <input 
                            v-model="question.column_name" 
                            type="text" 
                            class="w-full border p-2 rounded mb-2 bg-gray-200" 
                            placeholder="Enter column name for new questions only"
                            :disabled="question.column_name === 'events_location' || question.column_name === 'email_address'
                            || question.column_name === 'mobile_number' || question.column_name === 'first_name' || question.column_name === 'last_name'
                            || question.column_name === 'age' || question.column_name === 'gender' || question.column_name === 'street_address' || question.column_name === 'street_address_2' || question.column_name === 'city'
                            || question.column_name === 'state' || question.column_name === 'zip_code' || question.column_name === 'country'"
                        />

                        <label class="font-medium">Type:</label>
                        <select v-model="question.type" class="w-full border p-2 rounded mb-2" 
                            :disabled="!question.isNew && question.column_name !== 'age'">
                            <option value="email">Email</option>
                            <option value="text">Text Input</option>
                            <option value="textarea">Text Area</option>
                            <option value="dropdown">Dropdown</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                        </select>

                        <div class="text-sm text-red-500 mb-2" v-if="!question.isNew && question.column_name !== 'age'">
                            Note: To change the field type of existing questions, please contact the administrator
                        </div>

                        <div class="text-sm text-gray-500 mb-2" v-if="question.type === 'textarea'">
                            Note: Text Area will be stored as a long text field in the database
                        </div>

                        <div class="text-sm text-gray-500 mb-2" v-if="question.type === 'text'">
                            Note: Text Input will be stored as a string field in the database
                        </div>

                        <div class="text-sm text-gray-500 mb-2" v-if="question.type === 'email'">
                            Note: Email will be stored as a string field in the database
                        </div>

                        <div class="text-sm text-gray-500 mb-2" v-if="question.type === 'number'">
                            Note: Number will be stored as a string field in the database
                        </div>

                        <div class="text-sm text-gray-500 mb-2" v-if="question.type === 'date'">
                            Note: Date will be stored as a date field in the database
                        </div>

                        <div class="text-sm text-gray-500 mb-2" v-if="question.type === 'dropdown'">
                            Note: {{ question.allowMultiple ? 'Multiple selection dropdown will be stored as a long text field' : 'Single selection dropdown will be stored as a string field' }} in the database
                        </div>

                        <!-- ✅ Dropdown Options -->
                        <div v-if="question.type === 'dropdown'">
                            <div class="mb-3 flex items-center">
                                <input 
                                    type="checkbox" 
                                    :id="`multiple-option-${question.column_name}`" 
                                    v-model="question.allowMultiple" 
                                    class="mr-2"
                                />
                                <label :for="`multiple-option-${question.column_name}`" class="font-medium">
                                    Allow multiple selection
                                </label>
                            </div>
                            <label class="font-medium">Dropdown Options:</label>
                            <div v-for="(option, optIndex) in question.options" :key="optIndex" 
                                draggable="true"
                                @dragstart="dragStartOption(question, optIndex)"  
                                @dragover.prevent
                                @drop="dropOption(question, optIndex)" 
                                class="flex gap-2 mb-2">
                                <div class="m-auto" title="Hide this Location" v-if="question.column_name === 'events_location'">
                                    <input 
                                        type="checkbox" 
                                        :checked="question.hiddenOptions && question.hiddenOptions.includes(option)" 
                                        @change="toggleHiddenOption(question, option)"
                                    >
                                </div>
                                <input v-model="question.options[optIndex]" type="text" class="flex-1 border p-2 rounded" />
                                <button @click="question.options.push('')" class="bg-green-500 text-white px-3 py-2 rounded">
                                    <i class="fa-solid fa-add"></i>
                                </button>
                                <button @click="removeDropdownOption(question, optIndex)" class="bg-red-500 text-white px-3 py-2 rounded"  v-if="question.options.length > 2">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                            <div class="mt-3 flex items-center">
                                <input 
                                    type="checkbox" 
                                    :id="`other-option-${question.column_name}`" 
                                    v-model="question.hasOtherOption" 
                                    class="mr-2"
                                />
                                <label :for="`other-option-${question.column_name}`" class="font-medium">
                                    Include "Other" option with text input
                                </label>
                            </div>
                        </div>

                        <div v-if="question.column_name !== 'events_location' && question.column_name !== 'email_address'
                        && question.column_name !== 'mobile_number' && question.column_name !== 'first_name' && question.column_name !== 'last_name'
                        && question.column_name !== 'age' && question.column_name !== 'gender' && question.column_name !== 'street_address'
                        && question.column_name !== 'street_address_2' && question.column_name !== 'city' && question.column_name !== 'state'
                        && question.column_name !== 'zip_code' && question.column_name !== 'country'">
                            <button @click="removeQuestion(index)" class="bg-red-500 text-white px-3 py-1 rounded mt-2">Remove</button>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button @click="addQuestion" class="bg-blue-500 text-white px-3 py-2 rounded">Add Question</button>
                    <button @click="saveForm" class="bg-green-500 text-white px-4 py-2 rounded">Update Form</button>
                </div>
            </div>

            <!-- Modal -->
            <div v-if="showModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-lg mx-4 sm:mx-auto">
                    <h2 class="text-lg font-bold mb-4">Locations for {{ events.event_name }}</h2>
                    <ul>
                        <li 
                            v-for="location in formattedLocations" 
                            :key="location.id" 
                            class="mb-2 p-2 border rounded cursor-pointer hover:bg-gray-100"
                            @click="selectLocation(location.name)"
                        >
                            {{ location.name }} - {{ location.formattedDate }} - {{ location.formattedTime }}
                        </li>
                    </ul>
                    <button 
                        @click="showModal = false" 
                        class="mt-4 bg-red-500 text-white px-4 py-2 rounded w-full sm:w-auto"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>