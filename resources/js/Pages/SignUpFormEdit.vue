<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// ✅ Receive props
const props = defineProps({
    events: Object,
    form: Object, // 👈 Form data (for editing)
    locations: Array, // Add locations prop
    columnStats: { type: Object, default: () => ({}) }, // saved answers per column
    orphanColumns: { type: Array, default: () => [] }, // table columns no question uses
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
        question.allowMultiple = question.column_name === 'fave_sport';
    }

    // Captured after the defaults above, so "unchanged" really means unchanged:
    // where the answers currently live and what shape the column is in. A renamed or
    // retyped question is compared against this.
    question.original_column_name = question.column_name;
    question.original_type = question.type;
    question.original_allowMultiple = !!question.allowMultiple;
});

// ── Keeping or dropping a question's column ─────────────────────────────────
// Taking a question off the questionnaire and deleting the answers it already
// collected are two separate decisions. Nothing is dropped unless it is listed here.
const columnsToDrop = ref([]);

const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);

// undefined = the column isn't in the table yet, so there is nothing to keep or lose
const storedAnswers = (columnName) => props.columnStats?.[(columnName || '').trim()];
const hasStoredColumn = (columnName) => storedAnswers(columnName) !== undefined;

const markColumnForDrop = (columnName) => {
    const name = (columnName || '').trim();
    if (name && !columnsToDrop.value.includes(name)) {
        columnsToDrop.value.push(name);
    }
};

const unmarkColumnForDrop = (columnName) => {
    const name = (columnName || '').trim();
    columnsToDrop.value = columnsToDrop.value.filter((column) => column !== name);
};

// Returns 'keep' | 'drop' | 'cancel'
const askKeepOrDrop = async (title, columnNames) => {
    const stored = columnNames.filter(hasStoredColumn);

    // Never saved to the table — no data question to answer.
    if (stored.length === 0) {
        const plain = await Swal.fire({
            title,
            text: 'Are you sure you want to remove this from the form?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, remove it!',
            cancelButtonText: 'No, cancel',
        });
        return plain.isConfirmed ? 'keep' : 'cancel';
    }

    const total = stored.reduce((sum, column) => sum + (storedAnswers(column) || 0), 0);
    const list = stored.map((column) => `<code>${escapeHtml(column)}</code>`).join(', ');
    const plural = stored.length > 1 ? 's' : '';

    const choice = await Swal.fire({
        title,
        html: `
            <p class="text-sm">Column${plural}: ${list}<br><b>${total}</b> saved answer${total === 1 ? '' : 's'}.</p>
            <p class="text-sm mt-3 text-left">
                <b>Keep the data</b> — the column and everything in it stay in the table and in exports,
                the question just stops showing on the form.<br><br>
                <b>Delete the column${plural}</b> — the stored answers are removed for good.
            </p>`,
        icon: 'warning',
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: 'Keep the data',
        denyButtonText: `Delete the column${plural}`,
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#16a34a',
        denyButtonColor: '#dc2626',
    });

    if (choice.isConfirmed) return 'keep';
    if (!choice.isDenied) return 'cancel';

    // Deleting real answers gets a second look; an empty column does not need one.
    if (total === 0) return 'drop';

    const confirmDelete = await Swal.fire({
        title: `Delete ${total} saved answer${total === 1 ? '' : 's'}?`,
        html: `${list} will be permanently deleted <b>when you press Update Form</b>. Nothing is
               removed from the database until then. This cannot be undone.`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: 'Queue it for deletion',
        cancelButtonText: 'No, keep the data',
        confirmButtonColor: '#dc2626',
    });

    return confirmDelete.isConfirmed ? 'drop' : 'keep';
};

// Columns an earlier edit left behind. No question points at them, so the only
// thing left to decide is whether to keep them or delete them for good.
const leftoverColumns = ref([...props.orphanColumns]);

const deleteLeftoverColumn = async (column) => {
    const stored = storedAnswers(column) || 0;

    const confirmDelete = await Swal.fire({
        title: `Delete ${escapeHtml(column)}?`,
        html: `<p class="text-sm">This column holds <b>${stored}</b> saved answer${stored === 1 ? '' : 's'}
               and is not used by any question.</p>
               <p class="text-sm mt-2">It will be permanently deleted when you save the form.
               This cannot be undone.</p>`,
        icon: stored > 0 ? 'error' : 'warning',
        showCancelButton: true,
        confirmButtonText: 'Queue it for deletion',
        cancelButtonText: 'No, keep it',
        confirmButtonColor: '#dc2626',
    });

    if (!confirmDelete.isConfirmed) return;

    markColumnForDrop(column);
    Swal.fire('Queued', `${column} will be deleted when you press Update Form.`, 'info');
    leftoverColumns.value = leftoverColumns.value.filter((name) => name !== column);
};

// Nothing on this page touches the database until Update Form is pressed. That is
// easy to miss after a delete dialog that says "yes, delete it", so the page says so.
const hasUnsavedChanges = ref(false);
watch(questions, () => { hasUnsavedChanges.value = true; }, { deep: true });
watch(columnsToDrop, () => { hasUnsavedChanges.value = true; }, { deep: true });

const warnOnLeave = (event) => {
    if (!hasUnsavedChanges.value) return;
    event.preventDefault();
    event.returnValue = '';
};

onMounted(() => window.addEventListener('beforeunload', warnOnLeave));
onUnmounted(() => window.removeEventListener('beforeunload', warnOnLeave));

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
const removeQuestion = async (index) => {
    // Without this the whole handler could die mid-dialog and leave the question
    // sitting there with no explanation.
    try {
        await runRemoveQuestion(index);
    } catch (error) {
        console.error('Removing the question failed', error);
        Swal.fire('Could not remove it', String(error?.message || error), 'error');
    }
};

const runRemoveQuestion = async (index) => {
    const question = questions.value[index];
    const columnName = question?.original_column_name || question?.column_name;

    // The location dropdown is not just another question — it is what sets
    // location_id on a submission, which drives the Win Sheets and the Mailchimp
    // sync. Removable, but not by accident.
    if (question?.column_name === 'events_location') {
        const proceed = await Swal.fire({
            title: 'Remove the location question?',
            html: `<p class="text-sm text-left">Entries will no longer be tied to a location, so they will not
                   appear on any Win Sheet and will not auto-sync to that location's Mailchimp audience.</p>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'I understand, continue',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
        });
        if (!proceed.isConfirmed) return;
    }

    const choice = await askKeepOrDrop('Remove Question?', [columnName]);
    if (choice === 'cancel') return;

    if (choice === 'drop') {
        markColumnForDrop(columnName);
    }

    questions.value.splice(index, 1);
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

// Escape closes it, and the page behind it stops scrolling while it is open.
const closeOnEscape = (event) => {
    if (event.key === 'Escape') showModal.value = false;
};

watch(showModal, (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
});

onMounted(() => window.addEventListener('keydown', closeOnEscape));
onUnmounted(() => {
    window.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = '';
});

// A renamed question can either take its column (and answers) with it, or start
// fresh — in which case the old column becomes a removal like any other.
// Returns the confirmed renames, or null if the admin backed out.
const resolveRenames = async () => {
    const renames = [];

    for (const question of questions.value) {
        const from = (question.original_column_name || '').trim();
        const to = (question.column_name || '').trim();

        if (!from || from === to || !hasStoredColumn(from)) continue;

        const stored = storedAnswers(from);
        const choice = await Swal.fire({
            title: 'Column name changed',
            html: `
                <p class="text-sm"><code>${escapeHtml(from)}</code> → <code>${escapeHtml(to)}</code><br>
                <b>${stored}</b> saved answer${stored === 1 ? '' : 's'} in the old column.</p>
                <p class="text-sm mt-3 text-left">
                    <b>Move the data</b> — the column is renamed, answers come with it.<br><br>
                    <b>Start a new column</b> — <code>${escapeHtml(to)}</code> starts empty and you choose what
                    happens to <code>${escapeHtml(from)}</code>.
                </p>`,
            icon: 'question',
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: 'Move the data',
            denyButtonText: 'Start a new column',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#16a34a',
        });

        if (choice.isConfirmed) {
            renames.push({ from, to });
            unmarkColumnForDrop(from);
            continue;
        }

        if (!choice.isDenied) return null;

        const fate = await askKeepOrDrop(`What about ${from}?`, [from]);
        if (fate === 'cancel') return null;
        if (fate === 'drop') markColumnForDrop(from);
        else unmarkColumnForDrop(from);
    }

    return renames;
};

// How each question type is stored — mirrors questionColumnType() on the server.
const STORAGE_LABELS = { string: 'short text (255 characters)', longText: 'long text', date: 'date' };
const TYPE_LABELS = {
    text: 'Text Input', email: 'Email', textarea: 'Text Area',
    dropdown: 'Dropdown', number: 'Number', date: 'Date',
};

const storageTypeFor = (type, allowMultiple) => {
    if (type === 'textarea') return 'longText';
    if (type === 'date') return 'date';
    if (type === 'dropdown') return allowMultiple ? 'longText' : 'string';
    if (type === 'text' || type === 'email' || type === 'number') return 'string';
    return null;
};

const typeLabel = (type, allowMultiple) =>
    `${TYPE_LABELS[type] || type}${type === 'dropdown' && allowMultiple ? ' (multiple)' : ''}`;

// Changing a question's type rewrites its column. Empty columns convert silently;
// anything holding answers is confirmed first, because the database can refuse a
// conversion the existing answers do not fit.
// Returns the approved column names, or null if the admin backed out.
const resolveTypeChanges = async () => {
    const approved = [];

    for (const question of questions.value) {
        const original = (question.original_column_name || '').trim();
        if (!original || !hasStoredColumn(original)) continue;

        const before = storageTypeFor(question.original_type, question.original_allowMultiple);
        const after = storageTypeFor(question.type, question.allowMultiple);
        if (!after || before === after) continue;

        const column = (question.column_name || '').trim();
        const stored = storedAnswers(original) || 0;

        // Nothing stored yet — no answers to convert, so no decision to make.
        if (stored === 0) {
            approved.push(column);
            continue;
        }

        const risky = after === 'date' || (before === 'longText' && after === 'string');
        const choice = await Swal.fire({
            title: 'Change the field type?',
            html: `
                <p class="text-sm"><b>${escapeHtml(question.text || column)}</b><br>
                ${escapeHtml(typeLabel(question.original_type, question.original_allowMultiple))}
                → ${escapeHtml(typeLabel(question.type, question.allowMultiple))}<br>
                stored as ${STORAGE_LABELS[before]} → ${STORAGE_LABELS[after]}</p>
                <p class="text-sm mt-2"><b>${stored}</b> saved answer${stored === 1 ? '' : 's'} in
                <code>${escapeHtml(original)}</code>.</p>
                ${risky ? `<p class="text-sm mt-3 text-left text-red-600">Existing answers that do not fit
                    ${STORAGE_LABELS[after]} will be rejected by the database. If that happens the column and
                    the question are both left as they are, and you will be told which ones.</p>` : ''}`,
            icon: risky ? 'warning' : 'question',
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: 'Change the type',
            denyButtonText: `Keep ${typeLabel(question.original_type, question.original_allowMultiple)}`,
            cancelButtonText: 'Cancel',
            confirmButtonColor: risky ? '#dc2626' : '#16a34a',
        });

        if (choice.isConfirmed) {
            approved.push(column);
            continue;
        }

        if (!choice.isDenied) return null;

        // Put the question back on the type its column actually has.
        question.type = question.original_type;
        question.allowMultiple = question.original_allowMultiple;
    }

    return approved;
};

// ✅ Save the form (update)
const saveForm = async () => {
    // Every failure below used to return silently, so a save that never happened
    // looked exactly like a save that did. Nothing here exits without saying why.
    try {
        await runSave();
    } catch (error) {
        console.error('Sign-up form save failed', error);
        Swal.fire('Could not save', String(error?.message || error), 'error');
    }
};

const runSave = async () => {
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

    const cancelled = () => Swal.fire('Nothing saved', 'You cancelled, so the form was left as it was.', 'info');

    const renames = await resolveRenames();
    if (renames === null) return cancelled(); // backed out of a rename decision

    const typeChanges = await resolveTypeChanges();
    if (typeChanges === null) return cancelled(); // backed out of a type-change decision

    // A column still used by a question is never dropped, whatever was queued
    // earlier — the admin may have re-added it or renamed something back.
    const liveColumns = questions.value.map((question) => (question.column_name || '').trim());
    const renamedFrom = renames.map((rename) => rename.from);
    const dropColumns = columnsToDrop.value.filter(
        (column) => !liveColumns.includes(column) && !renamedFrom.includes(column)
    );

    // Removed questions whose column was left alone — worth spelling out, since
    // "kept" data keeps showing up in exports.
    const originalColumns = JSON.parse(props.form.questions || '[]').map((question) => question.column_name);
    const keptColumns = originalColumns.filter(
        (column) =>
            hasStoredColumn(column) &&
            !liveColumns.includes(column) &&
            !renamedFrom.includes(column) &&
            !dropColumns.includes(column)
    );

    let summary = 'This will update the sign-up form.';
    if (renames.length) {
        summary += `<br><br><b>Columns renamed (data moves):</b><br>${renames
            .map((rename) => `${escapeHtml(rename.from)} → ${escapeHtml(rename.to)}`)
            .join('<br>')}`;
    }
    if (keptColumns.length) {
        summary += `<br><br><b>Removed from the form, data kept:</b><br>${keptColumns.map(escapeHtml).join(', ')}`;
    }
    if (dropColumns.length) {
        summary += `<br><br><b class="text-red-600">Columns deleted permanently:</b><br>${dropColumns
            .map(escapeHtml)
            .join(', ')}`;
    }
    if (typeChanges.length) {
        summary += `<br><br><b>Field types changed:</b><br>${typeChanges.map(escapeHtml).join(', ')}`;
    }

    const result = await Swal.fire({
        title: 'Save changes?',
        html: summary,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, update it!',
        cancelButtonText: 'No, cancel'
    });

    if (!result.isConfirmed) return cancelled();

    router.post(route('signup.update', { eventId: props.events.id }), {
        heading: headerText.value,
        event_description: descriptionText.value,
        terms_link: termsLink.value,
        privacy_link: policyLink.value,
        // The original_* keys are only bookkeeping for this page; they are re-derived
        // on load and would go stale the moment a rename or retype is saved.
        questions: questions.value.map(
            ({ original_column_name, original_type, original_allowMultiple, ...question }) => question
        ),
        drop_columns: dropColumns,
        column_renames: renames,
        column_type_changes: typeChanges,
    }, {
        onSuccess: (page) => {
            hasUnsavedChanges.value = false;
            // A conversion the database refused comes back here rather than passing silently.
            const failed = page?.props?.flash?.error;
            if (failed) {
                Swal.fire('Saved, with one problem', failed, 'warning');
            } else {
                Swal.fire('Saved!', 'Sign Up Form has been updated.', 'success');
            }
            router.get(route('signup.index', { eventId: props.events.id }));
        },
        // A rejected save used to leave no trace at all on screen.
        onError: (errors) => {
            console.error('Sign-up form update rejected', errors);
            const detail = Object.values(errors || {}).join('\n') || 'The server rejected the update.';
            Swal.fire('Not saved', detail, 'error');
        },
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

                        <p class="text-xs mb-2" v-if="hasStoredColumn(question.original_column_name)"
                            :class="storedAnswers(question.original_column_name) > 0 ? 'text-amber-700' : 'text-gray-500'">
                            <i class="fa-solid fa-database"></i>
                            {{ storedAnswers(question.original_column_name) }} saved answer(s) in
                            <b>{{ question.original_column_name }}</b>
                        </p>

                        <label class="font-medium">Type:</label>
                        <select v-model="question.type" class="w-full border p-2 rounded mb-2">
                            <option value="email">Email</option>
                            <option value="text">Text Input</option>
                            <option value="textarea">Text Area</option>
                            <option value="dropdown">Dropdown</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                        </select>

                        <div class="text-sm text-amber-700 mb-2"
                            v-if="!question.isNew
                                && storageTypeFor(question.type, question.allowMultiple)
                                    !== storageTypeFor(question.original_type, question.original_allowMultiple)">
                            Note: this changes how the column is stored
                            ({{ typeLabel(question.original_type, question.original_allowMultiple) }}
                            → {{ typeLabel(question.type, question.allowMultiple) }}).
                            You will be asked to confirm before it is applied.
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

                        <!-- Email is the one question that stays: it is how a submission is
                             matched to a contact and to the newsletter audience. -->
                        <div v-if="question.column_name !== 'email_address'">
                            <button @click="removeQuestion(index)" class="bg-red-500 text-white px-3 py-1 rounded mt-2">Remove</button>
                        </div>
                    </div>
                </div>

                <!-- Columns left behind by earlier edits. Nothing on the questionnaire
                     points at them, so this panel is the only way to clear them out. -->
                <div v-if="leftoverColumns.length" class="mt-6 rounded border border-amber-300 bg-amber-50 p-4">
                    <h3 class="font-semibold text-amber-900">Columns not used by any question</h3>
                    <p class="mb-3 text-sm text-amber-800">
                        These stay in the table and in exports. Delete one to remove it and its answers for good.
                    </p>
                    <div
                        v-for="column in leftoverColumns"
                        :key="column"
                        class="mb-2 flex items-center justify-between gap-3 rounded border bg-white px-3 py-2"
                    >
                        <div>
                            <p class="font-medium text-gray-800">{{ column }}</p>
                            <p class="text-xs text-gray-500">
                                {{ storedAnswers(column) || 0 }} saved answer(s)
                            </p>
                        </div>
                        <button
                            @click="deleteLeftoverColumn(column)"
                            class="shrink-0 rounded bg-red-500 px-3 py-1 text-sm text-white hover:bg-red-600"
                        >
                            Delete column
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button @click="addQuestion" class="bg-blue-500 text-white px-3 py-2 rounded">Add Question</button>
                    <button @click="saveForm" class="bg-green-500 text-white px-4 py-2 rounded">Update Form</button>
                </div>

                <!-- Removing a question and deleting its column are queued, not applied.
                     Nothing reaches the database until this bar's button is pressed. -->
                <div v-if="hasUnsavedChanges"
                    class="sticky bottom-4 z-40 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border-2 border-amber-400 bg-amber-50 px-4 py-3 shadow-lg">
                    <div>
                        <p class="font-semibold text-amber-900">Not saved yet</p>
                        <p class="text-sm text-amber-800">
                            <span v-if="columnsToDrop.length">
                                Queued for deletion: <b>{{ columnsToDrop.join(', ') }}</b>.
                            </span>
                            Press Update Form to apply your changes — nothing has changed in the database yet.
                        </p>
                    </div>
                    <button @click="saveForm"
                        class="shrink-0 rounded bg-green-600 px-4 py-2 font-medium text-white hover:bg-green-700">
                        Update Form
                    </button>
                </div>
            </div>

            <!-- Modal -->
            <div
                v-if="showModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 p-4"
                @click.self="showModal = false"
            >
                <!-- Capped at 85vh with the list as the only scrolling part, so a long
                     location list never pushes the header or Close button off screen. -->
                <div class="flex w-full max-w-lg max-h-[85vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="flex shrink-0 items-start justify-between gap-4 border-b px-5 py-4">
                        <div>
                            <h2 class="text-lg font-bold leading-tight">Locations</h2>
                            <p class="text-sm text-gray-500">
                                {{ events.event_name }} · {{ formattedLocations.length }}
                                location{{ formattedLocations.length === 1 ? '' : 's' }}
                            </p>
                        </div>
                        <button
                            @click="showModal = false"
                            class="-mr-1 rounded px-2 py-1 text-xl leading-none text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                            aria-label="Close"
                        >
                            &times;
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto px-5 py-4">
                        <p v-if="!formattedLocations.length" class="py-6 text-center text-sm text-gray-500">
                            No locations for this event yet.
                        </p>
                        <ul v-else class="divide-y">
                            <li v-for="location in formattedLocations" :key="location.id" class="py-2">
                                <p class="font-medium text-gray-800">{{ location.name }}</p>
                                <p class="text-sm text-gray-500">
                                    {{ location.formattedDate }}
                                    <span v-if="location.formattedTime"> · {{ location.formattedTime }}</span>
                                </p>
                            </li>
                        </ul>
                    </div>

                    <div class="shrink-0 border-t px-5 py-3 text-right">
                        <button
                            @click="showModal = false"
                            class="w-full rounded bg-red-500 px-4 py-2 text-white hover:bg-red-600 sm:w-auto"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>