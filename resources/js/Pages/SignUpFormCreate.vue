<script setup>
import { ref, watch, watchEffect, onMounted, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// ✅ Receive `events` as a prop
const props = defineProps({ 
    events: Number,
    locations: Array,
    eventValues: Object
}); 

// Format locations to display date and time in the desired format
const formattedLocations = computed(() => {
    return props.locations.map(location => {
        return {
            ...location,
            formattedDate: formatDate(location.date),
            formattedTime: formatTime(location.time)
        };
    });
});

// Function to format date to "March 07, 2025" format
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// Format date to "March 07, 2025" using only the date string (no timezone conversion)
function formatDate(dateString) {
    if (!dateString) return '';
    try {
        if (dateString.includes(',')) return dateString;
        const match = String(dateString).trim().match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (!match) return dateString;
        const [, y, m, d] = match;
        const monthIdx = parseInt(m, 10) - 1;
        if (monthIdx < 0 || monthIdx > 11) return dateString;
        const day = parseInt(d, 10);
        const month = MONTH_NAMES[monthIdx];
        return `${month} ${String(day).padStart(2, '0')}, ${y}`;
    } catch (e) {
        console.error("Error formatting date:", e);
        return dateString;
    }
}

// Format time to "7:00PM" using only the time string (no timezone conversion)
function formatTime(timeString) {
    if (!timeString) return '';
    try {
        if (timeString.includes('AM') || timeString.includes('PM')) {
            return timeString.replace(/\s+/g, '');
        }
        if (timeString.includes(':')) {
            const parts = timeString.split(':');
            let hours = parseInt(parts[0], 10) || 0;
            const minutes = parseInt(parts[1], 10) || 0;
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            return `${hours}:${String(minutes).padStart(2, '0')}${ampm}`;
        }
        return timeString;
    } catch (e) {
        console.error("Error formatting time:", e);
        return timeString;
    }
}

const showModal = ref(false); // Controls the visibility of the modal
// Add preview toggle
const showPreview = ref(false);

const selectLocation = (locationName) => {
    selectedLocation.value = locationName; // Update the selected location
    showModal.value = false; // Close the modal
};

// Track selected values for preview
const selectedValues = ref({});
// Track "Other" input values
const otherValues = ref({});

// Check if "Other" is selected and handle accordingly
const checkForOtherOption = (question) => {
    if (selectedValues.value[question.column_name] !== 'Other') {
        // Clear the "Other" value if something else is selected
        otherValues.value[question.column_name] = '';
    }
};
    
// ✅ Default Questions with Column Names
const defaultQuestions = ref([
    { text: 'Email Address', type: 'email', column_name: 'email_address', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'First Name', type: 'text', column_name: 'first_name', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'Last Name', type: 'text', column_name: 'last_name', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'Street Address', type: 'text', column_name: 'street_address', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'Address Line 2', type: 'text', column_name: 'street_address_2', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'City', type: 'text', column_name: 'city', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'State', type: 'text', column_name: 'state', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'Zip Code', type: 'text', column_name: 'zip_code', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'Country', type: 'dropdown', column_name: 'country', options: ['Australia', 'New Zealand', 'USA', 'Canada', 'Germany', 'United Kingdom', 'Europe'], hasOtherOption: false, allowMultiple: false },
    { text: 'Mobile Number', type: 'number', column_name: 'mobile_number', options: [], hasOtherOption: false, allowMultiple: false },
    { text: 'Age', type: 'dropdown', column_name: 'age', options: ['Under 21', '22-44', '45+'], hasOtherOption: false, allowMultiple: false },
    { text: 'Gender', type: 'dropdown', column_name: 'gender', options: ['Female', 'Male', 'Nonbinary/Other'], hasOtherOption: false, allowMultiple: false },
    { text: 'Combined Household Income?', type: 'dropdown', column_name: 'household_income', options: ['>$150,000', '$100,000-$150,000', '$66,000-$99,000', '<$66,000', 'Prefer not to say'], hasOtherOption: false, allowMultiple: false },
    { text: 'Where did you hear about this event?', type: 'dropdown', column_name: 'where_did_you_hear', options: ['FB/IG', 'Poster in store', 'Email', 'Word of mouth'], hasOtherOption: true, allowMultiple: false },
    { text: 'Favorite adventure sport?', type: 'dropdown', column_name: 'fave_sport', options: ['Snow Sports (Skiing, Snowboarding, Snowshoeing)',
                'Climbing (Indoor, Outdoor, Bouldering, Slacklining)',
                'Trail Sports (Trail Running, Trail Walking)',
                'Skate Sports (Skateboarding, Rollerblading)',
                'Cycling (Mountain Biking, Road Cycling, BMX)',
                'Water Sports (Kayaking, Canoeing, Surfing, Windsurfing, Fly Fishing, Scuba Diving, Paddleboarding)',
                'Outdoor Activities (Hiking, Camping)',
                'Aerial Sports (Paragliding, Hang Gliding)',
                'Extreme Sports (Bungee Jumping, BASE Jumping)'], hasOtherOption: true, allowMultiple: true },
    { text: 'How much would you spend on equipment?', type: 'dropdown', column_name: 'how_much_spend', options: ['Less than $500', '$500-$1,000', 'More than $1,000'], hasOtherOption: false, allowMultiple: false },
    { text: 'How often do you climb? (Specify type)', type: 'dropdown', column_name: 'how_often_climb', options: ['More than once a year', 'Once a year', 'Once every 2 years', 'Never'], hasOtherOption: false, allowMultiple: false },
    { text: 'How often do you climb overseas? (Specify type)', type: 'dropdown', column_name: 'how_often_climb_overseas', options: ['More than once a year', 'Once a year', 'Once every 2 years', 'Never'], hasOtherOption: false, allowMultiple: false },
    { text: 'How many days per year do you climb? (Specify type)', type: 'dropdown', column_name: 'how_often_climb_per_year', options: ['1-4 days', '5-10 days', '11-19 days', '20+ days', 'Never'], hasOtherOption: false, allowMultiple: false },
]);

// ✅ Reactive copy of questions (to modify in UI)
const questions = ref([...defaultQuestions.value]);
const ADDRESS_COLUMNS = ['street_address','street_address_2','city','state','zip_code','country'];
const ADDRESS_QUESTIONS_TEMPLATE = [
  { text: 'Street Address', type: 'text', column_name: 'street_address', options: [], hasOtherOption: false, allowMultiple: false },
  { text: 'Address Line 2', type: 'text', column_name: 'street_address_2', options: [], hasOtherOption: false, allowMultiple: false },
  { text: 'City', type: 'text', column_name: 'city', options: [], hasOtherOption: false, allowMultiple: false },
  { text: 'State', type: 'text', column_name: 'state', options: [], hasOtherOption: false, allowMultiple: false },
  { text: 'Zip Code', type: 'text', column_name: 'zip_code', options: [], hasOtherOption: false, allowMultiple: false },
  { text: 'Country', type: 'dropdown', column_name: 'country', options: ['Australia', 'New Zealand', 'USA', 'Canada', 'Germany', 'United Kingdom', 'Europe'], hasOtherOption: false, allowMultiple: false },
];
const collectAddress = ref(true);

const removeAddressQuestions = () => {
  questions.value = questions.value.filter(q => !ADDRESS_COLUMNS.includes(q.column_name));
};

const addAddressQuestionsIfMissing = () => {
  // Insert after 'last_name' if present; otherwise append
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
const headerText = ref('GET A CHANCE TO WIN AMAZING PRIZES!');
const descriptionText = ref('*By entering the competition you accept the competition terms and conditions and consent to receiving marketing materials related to the offerings of Adventure Entertainment and our partners.');
const termsLink = ref('#');
const policyLink = ref('https://adventureentertainment.com/privacy-policy/');
const mailchimpSignupUrl = ref('');

// ✅ Dragging logic
const draggedQuestionIndex = ref(null);
const dragStart = (index) => {
    draggedQuestionIndex.value = index;
};
const selectedLocation = ref(''); // Define the reactive variable for the selected location
const drop = (index) => {
    if (draggedQuestionIndex.value !== null) {
        const movedQuestion = questions.value.splice(draggedQuestionIndex.value, 1)[0];
        questions.value.splice(index, 0, movedQuestion);
        draggedQuestionIndex.value = null;
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
    if (question.options.length > 2) {
        question.options.splice(optIndex, 1);
    }
};

// ✅ Save the form
const saveForm = () => {
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
        text: 'This will create the signup form.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, create it!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            router.post(route('signup.generate', { eventId: props.events }),{ 
                headerText: headerText.value,
                descriptionText: descriptionText.value,
                termsLink: termsLink.value,
                policyLink: policyLink.value,
                mailchimpSignupUrl: mailchimpSignupUrl.value,
                questions: questions.value,
            }, {
                onSuccess: () => {
                    Swal.fire('Saved!', 'Sign Up Form has been created.', 'success');
                    router.get(route('signup.index', { eventId: props.events }));
                }
            });
        }
    });
};

onMounted(() => {
    // console.log('Locations array:', props.locations);
    // console.log('Events:', props.eventValues);
});
</script>

<template>
    <Head title="Create Sign Up Form" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Create Sign Up Form for Event: {{ eventValues.event_name }}
            </h2>
        </template>

        <div class="py-6 mx-auto w-full px-4 md:w-1/2">
            <div class="bg-white p-6 shadow rounded-lg">
                <!-- ✅ Draggable Questions Section -->
                <h2 class="text-lg font-bold mb-3">Edit Header Text</h2>
                <div>
                    <label class="font-medium">Header Text:</label>
                    <input v-model="headerText" type="text" class="w-full border p-2 rounded mb-2" />

                    <label class="font-medium">Description Text:</label>
                    <textarea v-model="descriptionText" class="w-full border p-2 rounded mb-2 h-32"></textarea>

                    <label class="font-medium">Terms and Condition Link:</label>
                    <input v-model="termsLink" type="text" class="w-full border p-2 rounded mb-2" />

                    <label class="font-medium">Privacy Policy Link:</label>
                    <input v-model="policyLink" type="text" class="w-full border p-2 rounded mb-2" />

                    <label class="font-medium">Mailchimp Signup Form URL:</label>
                    <input v-model="mailchimpSignupUrl" type="text" class="w-full border p-2 rounded mb-2" placeholder="https://adventureentertainment.us1.list-manage.com/subscribe?u=..." />
                    <p class="text-xs text-gray-500 mb-2">Users who are not subscribed will be redirected here to resubscribe before completing the form.</p>
                </div>
            </div>
        </div>

        <div class="pb-5 mx-auto w-full px-4 md:w-1/2">
            <div class="bg-white p-6 shadow rounded-lg">
                
                <!-- ✅ Draggable Questions Section -->
                <h2 class="text-lg font-bold mb-3">Create Questions (Drag to Reorder)</h2>
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
                    
                    <!-- <button 
                        @click="showPreview = !showPreview" 
                        class="bg-purple-500 text-white px-4 py-2 rounded ml-2"
                    >
                        {{ showPreview ? 'Hide Preview' : 'Show Preview' }}
                    </button> -->
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
                                    :disabled="question.column_name === 'fave_sport'"
                                    class="mr-2"
                                />
                                <label :for="`multiple-option-${question.column_name}`" class="font-medium">
                                    Allow multiple selection
                                    <span v-if="question.column_name === 'fave_sport'" class="text-xs text-gray-500 ml-1">(always enabled)</span>
                                </label>
                            </div>
                            <label class="font-medium">Dropdown Options:</label>
                            <div v-for="(option, optIndex) in question.options" 
                                 draggable="true"
                                 @dragstart="dragStartOption(question, optIndex)" 
                                 @dragover.prevent
                                 @drop="dropOption(question, optIndex)" 
                                 :key="optIndex" 
                                 class="flex gap-2 mb-2">
                                <input v-model="question.options[optIndex]" type="text" class="flex-1 border p-2 rounded" />
                                <button @click="question.options.push('')" class="bg-green-500 text-white px-3 py-2 rounded">
                                    <i class="fa-solid fa-add"></i>
                                </button>
                                <button 
                                    @click="removeDropdownOption(question, optIndex)" 
                                    class="bg-red-500 text-white px-3 py-2 rounded"
                                    v-if="question.options.length > 2"
                                >
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                            
                            <!-- "Other" option checkbox -->
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
                            <button @click="removeQuestion(index)" class="bg-red-500 text-white px-3 py-1 rounded mt-2">Remove Question</button>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button @click="addQuestion" class="bg-blue-500 text-white px-3 py-2 rounded">Add Question</button>
                    <button @click="saveForm" class="bg-green-500 text-white px-4 py-2 rounded">Save Form</button>
                </div>
                

            </div>
           <!-- Modal -->
            <div v-if="showModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-lg mx-4 sm:mx-auto">
                    <h2 class="text-lg font-bold mb-4">Locations for {{ eventValues.event_name }}</h2>
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