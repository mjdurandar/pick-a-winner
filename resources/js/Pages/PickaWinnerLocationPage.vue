<script setup>
import { toast } from '@/stores/pawToast';
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import HowToPickAWinner from '@/Components/HowToPickAWinner.vue';
import { Head } from '@inertiajs/vue3';
import { syncState, enqueue, removeItem, pruneSynced, processQueue, startAutoSync, makeUuid, connectionQuality, pendingSyncDetail } from '@/stores/winnerSync';

const isSubmitting = ref(false); // Track submission state

// ✅ Search Query
const searchQuery = ref('');
const showFilters = ref(false);

// Panels, held in state rather than driven through bootstrap.Modal. A draw
// runs on a phone and a Bootstrap modal cannot be sized for a stage: the
// winner's name has to fill the screen. Same panels, same order, no library.
const showPick = ref(false);           // the draw itself
const showPrizeDetails = ref(false);   // naming the prize for a saved winner
const showHowTo = ref(false);          // how to pick a winner
const showMenu = ref(false);           // everything that is not the draw

// The film's own logo, uploaded on the event. Hidden if the file is missing,
// so a broken image never sits on the screen the audience is watching.
const logoBroken = ref(false);
const eventLogo = computed(() => (props.event?.event_logo && !logoBroken.value ? `/storage/${props.event.event_logo}` : ''));

// ✅ Dynamic Filter System - Support multiple filters based on questions
const filters = ref([]); // Array of filter objects: { questionColumn: '', questionText: '', filterValue: '' }
const filterCondition = ref('AND'); // 'AND' or 'OR'

// ✅ Get attendees for the current location only
const locationAttendees = computed(() => {
    return props.attendees.filter(attendee => attendee.location_id === props.location.id);
});

// ✅ Get all available questions for filtering (excluding system fields)
const availableQuestions = computed(() => {
    if (!props.form || !props.form.questions) return [];
    
    const questions = JSON.parse(props.form.questions || '[]');
    const systemFields = ['id', 'event_id', 'location_id', 'created_at', 'updated_at', 'events_location'];
    
    return questions.filter(question => 
        !systemFields.includes(question.column_name) &&
        locationAttendees.value.length > 0 &&
        locationAttendees.value[0][question.column_name] !== undefined
    );
});

// ✅ Get unique values for a specific question field (only from current location)
const getUniqueValuesForQuestion = (columnName) => {
    const values = [...new Set(locationAttendees.value.map(a => a[columnName]).filter(v => v !== null && v !== undefined && v !== ''))];
    return values.sort();
};

// ✅ Add a new filter
const addFilter = () => {
    filters.value.push({
        questionColumn: '',
        questionText: '',
        filterValue: ''
    });
};

// ✅ Remove a filter
const removeFilter = (index) => {
    filters.value.splice(index, 1);
};

// ✅ Reset all filters
const resetFilters = () => {
    filters.value = [];
};

// ✅ Check if any filters are active
const hasActiveFilters = computed(() => {
    return filters.value.some(f => f.questionColumn && f.filterValue);
});

// ✅ Get question object by column name
const getQuestionByColumn = (columnName) => {
    return availableQuestions.value.find(q => q.column_name === columnName);
};

// ✅ Check if question is Date of Birth
const isDateOfBirthQuestion = (columnName) => {
    const question = getQuestionByColumn(columnName);
    if (!question) return false;
    // Check if text contains "Date of Birth" or column name is date_of_birth/dob
    const textLower = (question.text || '').toLowerCase();
    const columnLower = (question.column_name || '').toLowerCase();
    return textLower.includes('date of birth') || 
           columnLower.includes('date_of_birth') || 
           columnLower.includes('dob') ||
           textLower.includes('birthday') ||
           textLower.includes('birth date');
};

// ✅ Check if question has options (dropdown with choices)
const questionHasOptions = (columnName) => {
    const question = getQuestionByColumn(columnName);
    return question && question.type === 'dropdown' && question.options && question.options.length > 0;
};

// ✅ Get options for a question (or age ranges for Date of Birth)
const getQuestionOptions = (columnName) => {
    if (isDateOfBirthQuestion(columnName)) {
        return [
            'Under 18',
            '18+',
            'Over 60'
        ];
    }
    const question = getQuestionByColumn(columnName);
    return question && question.options ? question.options : [];
};

// ✅ Calculate age from date of birth
const calculateAge = (dateOfBirth) => {
    if (!dateOfBirth) return null;
    
    try {
        const birthDate = new Date(dateOfBirth);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        
        // Adjust age if birthday hasn't occurred this year
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        
        return age;
    } catch (e) {
        console.error('Error calculating age:', e);
        return null;
    }
};

// ✅ Check if age matches the selected age range
const ageMatchesRange = (age, ageRange) => {
    if (age === null || age === undefined) return false;
    
    switch (ageRange) {
        case 'Under 18':
            return age < 18;
        case '18+':
            return age >= 18 && age <= 60;
        case 'Over 60':
            return age > 60;
        default:
            return false;
    }
};

// ✅ Update question text when column is selected
const updateQuestionText = (filterIndex) => {
    const filter = filters.value[filterIndex];
    if (filter.questionColumn) {
        const question = getQuestionByColumn(filter.questionColumn);
        if (question) {
            filter.questionText = question.text;
        }
    } else {
        filter.questionText = '';
    }
    filter.filterValue = ''; // Reset filter value when question changes
};

// ✅ Get filter explanation text
const getFilterExplanation = computed(() => {
    const activeFilters = filters.value.filter(f => f.questionColumn && f.filterValue);
    
    if (activeFilters.length === 0) {
        return 'No filters applied. All attendees are eligible.';
    }
    
    const filterDescriptions = activeFilters.map(filter => {
        const question = getQuestionByColumn(filter.questionColumn);
        const questionText = question ? question.text : filter.questionColumn;
        
        // Special handling for Date of Birth - show as age range
        if (isDateOfBirthQuestion(filter.questionColumn)) {
            return `${questionText} (Age: ${filter.filterValue})`;
        }
        
        return `${questionText} = "${filter.filterValue}"`;
    });
    
    if (filterCondition.value === 'AND') {
        return `Only attendees matching ALL of the following filters will be eligible: ${filterDescriptions.join(' AND ')}`;
    } else {
        return `Attendees matching ANY of the following filters will be eligible: ${filterDescriptions.join(' OR ')}`;
    }
});

// ✅ Get today's date in 'YYYY-MM-DD' format
const getTodayDate = () => {
    const today = new Date();
    return today.toISOString().split('T')[0]; // ✅ Ensures 'YYYY-MM-DD' in UTC
};

// ✅ Computed Property to Filter Attendees (only from current location)
const filteredAttendees = computed(() => {
    return locationAttendees.value.filter(attendee => {
        // ✅ Apply search filtering
        const matchesSearch = searchQuery.value
            ? `${attendee.first_name} ${attendee.last_name}`.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
              attendee.email_address.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
              attendee.gender.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
              attendee.mobile_number.toLowerCase().includes(searchQuery.value.toLowerCase())
            : true;

        return matchesSearch;
    });
});

// "How to Pick a Winner" — shown in a modal right here. No password: the host
// already unlocked this draw, and leaving the page mid-draw risks the winners
// still sitting in the offline sync queue.
const openInstructions = () => {
    showHowTo.value = true;
};

const formatLocationDateTime = (date, time) => {
    if (!date || !time) return '';

    const datetime = new Date(`${date}T${time}`);
    return datetime.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
};

// ✅ Define Props (Expecting location, event & attendees list)
const props = defineProps({
    location: Object,  // ✅ Selected location
    event: Object,     // ✅ Event details (includes table_name)
    attendees: Array,   // ✅ List of attendees from the dynamic table
    prizes: Array,      // ✅ List of prizes
    form: Object,       // ✅ Signup form with questions for dynamic filtering
});

// ✅ 'online' | 'weak' | 'offline' — weak means the device claims to be online
// but winners are not actually reaching the server (see stores/winnerSync.js).
const connection = computed(() => connectionQuality());
const syncDetail = computed(() => pendingSyncDetail());

// ✅ Winners saved on this device for this location (offline sync queue)
const queuedWinners = computed(() => {
    return syncState.items.filter(item =>
        item.meta?.type === 'create-winner' &&
        item.meta.event_id === props.event.id &&
        item.meta.location_id === props.location.id
    );
});

// ✅ Deletes queued on this device (offline sync queue)
const queuedDeletes = computed(() => {
    return syncState.items.filter(item =>
        item.meta?.type === 'delete-winner' &&
        item.meta.event_id === props.event.id
    );
});

// ✅ True when a prize row has a delete waiting/synced in the queue
const isQueuedForDelete = (prize) => {
    return queuedDeletes.value.some(item =>
        (item.meta.prize_id && item.meta.prize_id === prize.id) ||
        (item.meta.client_uuid && item.meta.client_uuid === prize.client_uuid)
    );
};

// ✅ Server prizes merged with locally saved winners not yet confirmed by the
// server, minus any rows with a queued delete.
const mergedPrizes = computed(() => {
    const serverUuids = new Set(props.prizes.map(p => p.client_uuid).filter(Boolean));
    const local = queuedWinners.value
        .filter(item => !serverUuids.has(item.meta.client_uuid))
        .map(item => ({
            id: item.server_id || null,
            client_uuid: item.meta.client_uuid,
            prize_name: item.payload.prize_name,
            winner: item.payload.winner_name,
            winner_email: item.payload.winner_email,
            winner_mobile_number: item.payload.winner_mobile_number,
            __local: true,
            __pending: item.status === 'pending',
        }));
    return [...props.prizes, ...local].filter(prize => !isQueuedForDelete(prize));
});

// ✅ Count of saves/deletes still waiting to reach the server
const pendingSyncCount = computed(() =>
    queuedWinners.value.filter(i => i.status === 'pending').length +
    queuedDeletes.value.filter(i => i.status === 'pending').length
);

// ✅ Polling to Refresh Table Every 5 Seconds
let pollingInterval;
let isPolling = false;
let lastPollAt = 0;

const fetchAttendees = () => {
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
        return; // ✅ Skip polling while offline — everything runs from local data
    }
    if (isPolling) {
        return; // ✅ Never stack reloads — on a slow link they pile up and choke it
    }
    // ✅ On a weak signal back off to 30s so the winner sync queue gets the
    // bandwidth. Refreshing the table matters far less than saving winners.
    if (connection.value === 'weak' && Date.now() - lastPollAt < 30000) {
        return;
    }
    isPolling = true;
    lastPollAt = Date.now();
    router.reload({
        only: ['attendees', 'prizes'], // ✅ Reloads only the attendees + prizes data, not the whole page
        preserveState: true, // ✅ Keeps the existing page state
        onSuccess: () => {
            // ✅ Drop local queue copies once the server confirms them
            pruneSynced(props.prizes);
        },
        onFinish: () => {
            isPolling = false;
        },
    });
};

onMounted(() => {
    startAutoSync(); // ✅ Background sync of locally saved winners
    processQueue();
    pollingInterval = setInterval(fetchAttendees, 5000);
});

onUnmounted(() => {
    clearInterval(pollingInterval);
    if (animationInterval) clearInterval(animationInterval);
});

// ✅ Queue the delete so it works offline too: the row disappears immediately
// and the database delete syncs in the background. Also removes any local
// save for the same winner (covers a save that is mid-sync on slow internet).
const queueDelete = (prize) => {
    if (prize.client_uuid) {
        removeItem(prize.client_uuid); // Drop the local save (pending or synced)
    }
    enqueue({
        key: 'delete-' + (prize.id || prize.client_uuid),
        url: route('prize.destroyQueued'),
        payload: {
            event_id: props.event.id,
            prize_id: prize.id || null,
            client_uuid: prize.client_uuid || null,
        },
        meta: {
            type: 'delete-winner',
            event_id: props.event.id,
            location_id: props.location.id,
            prize_id: prize.id || null,
            client_uuid: prize.client_uuid || null,
        },
    });
};

// Deleting asks first, in a panel of the page's own rather than a pop-up. A
// winner the server already has needs DELETE typed; one that only exists on
// this phone needs a single tap, exactly as before.
const deleteTarget = ref(null);
const deleteText = ref('');
const deleteBox = ref(null);
const deleteIsLocalOnly = computed(() => !!deleteTarget.value && !!deleteTarget.value.__local && !deleteTarget.value.id);
// Any capitals count: the word is there to stop a slip of the thumb, not to test typing.
const deleteReady = computed(() => deleteIsLocalOnly.value || deleteText.value.trim().toUpperCase() === 'DELETE');

const destroy = (prize) => {
    deleteTarget.value = prize;
    deleteText.value = '';
    nextTick(() => deleteBox.value?.focus());
};

const cancelDelete = () => {
    deleteTarget.value = null;
    deleteText.value = '';
};

const confirmDelete = () => {
    const prize = deleteTarget.value;
    if (!prize || !deleteReady.value) return;

    const localOnly = deleteIsLocalOnly.value;
    queueDelete(prize);
    cancelDelete();

    if (localOnly) {
        toast.success('Winner removed');
    } else if (connection.value === 'offline') {
        toast.success('Removed on this phone', 'It will be deleted from the server once the internet is back.');
    } else if (connection.value === 'weak') {
        toast.success('Removed on this phone', 'The internet is weak, so it is still being deleted from the server. Keep this page open.');
    } else {
        toast.success('Deleted');
    }
};

// ✅ Track selected prize and winner
const selectedPrize = ref(null);
const selectedWinner = ref(null);
const isPicking = ref(false); // ✅ Controls animation state
const winnerDisplay = ref(''); // ✅ Displays random names
let animationInterval = null;

// The drawn person's details, shown under their name. Display only: the draw
// itself never reads these.
const hasValue = (v) => v !== null && v !== undefined && String(v).trim() !== '';
const winnerDetails = computed(() => {
    const w = selectedWinner.value;
    if (!w) return [];
    return [
        { label: 'Email', value: w.email_address },
        { label: 'Phone', value: w.mobile_number },
        { label: 'Gender', value: w.gender },
        { label: 'Age', value: w.age },
    ].filter((row) => hasValue(row.value));
});
const prizeWinnerDetails = computed(() => {
    const p = selectedPrize.value;
    if (!p) return [];
    return [
        { label: 'Email', value: p.winner_email },
        { label: 'Phone', value: p.winner_mobile_number },
        { label: 'Gender', value: p.winner_gender || p.gender },
        { label: 'Age', value: p.winner_age || p.age },
    ].filter((row) => hasValue(row.value));
});

// Prize input for winner
const prizeForWinner = ref('');

// Function to update the prize for a winner
const updatePrizeForWinner = () => {
    if (!selectedPrize.value || !prizeForWinner.value) {
        toast.error('Type the prize first');
        return;
    }

    // ✅ Rows with a client_uuid go through the offline sync queue (idempotent
    // upsert), so assigning a prize also works on slow/no internet.
    if (selectedPrize.value.client_uuid) {
        enqueue({
            key: selectedPrize.value.client_uuid,
            url: route('prize.store'),
            payload: {
                client_uuid: selectedPrize.value.client_uuid,
                event_id: props.event.id,
                location_id: props.location.id,
                prize_name: prizeForWinner.value,
            },
            meta: {
                type: 'create-winner',
                client_uuid: selectedPrize.value.client_uuid,
                event_id: props.event.id,
                location_id: props.location.id,
            },
        });
        handleModalClose();
        toast.success('Prize saved');
        return;
    }

    // Legacy rows (created before offline support) still update online.
    const data = new FormData();
    data.append('prize_name', prizeForWinner.value);
    data.append('_method', 'PATCH');

    router.post(route('prize.update', selectedPrize.value.id), data, {
        onSuccess: () => {
            handleModalClose();
            toast.success('Prize saved');
        },
        onError: (errors) => {
            toast.error('The prize could not be saved', 'Check the internet and try again.');
            console.error(errors);
        }
    });
};

// ✅ Open Modal and Start Animation
const openPickWinnerModal = () => {
    if (!props.attendees.length) {
        toast.warning('Nobody has signed up at this screening yet');
        return;
    }
    if (!eligibleAttendees.value.length) {
        toast.warning('Nobody left to draw', 'Everyone in the draw has already won, or nobody matches the filter.');
        return;
    }

    selectedPrize.value = null;
    selectedWinner.value = null;
    winnerDisplay.value = 'Searching for a winner...';
    isPicking.value = true;

    showPick.value = true;

    animationInterval = setInterval(() => {
        const randomIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        const randomAttendee = eligibleAttendees.value[randomIndex];
        winnerDisplay.value = `${randomAttendee.first_name} ${randomAttendee.last_name}`;
    }, 100);

    setTimeout(() => {
        clearInterval(animationInterval);
        isPicking.value = false;

        const finalIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        selectedWinner.value = eligibleAttendees.value[finalIndex];
        winnerDisplay.value = `${selectedWinner.value.first_name} ${selectedWinner.value.last_name}`;
    }, 500);
};

// Add this computed property to find the attendee data (only from current location)
const findAttendeeByEmail = (email) => {
    return locationAttendees.value.find(attendee => attendee.email_address === email);
};

// Update the openPrizeDetailsModal function
const openPrizeDetailsModal = (winner) => {
    const attendeeData = findAttendeeByEmail(winner.winner_email);
    selectedPrize.value = {
        ...winner,
        winner_gender: attendeeData?.gender || '',
        winner_age: attendeeData?.age || ''
    };
    prizeForWinner.value = ''; // Reset the prize input

    showPrizeDetails.value = true;

    // Focus on the prize input after modal is shown
    nextTick(() => {
        if (prizeInput.value) {
            prizeInput.value.focus();
        }
    });
};

// ✅ Save the Winner (without prize initially)
// The winner is stored locally right away (offline sync queue) so slow/no
// internet never blocks the draw — it syncs to the server in the background.
const confirmWinner = () => {
    if (!selectedWinner.value) {
        toast.error('No winner selected');
        return;
    }

    if (isSubmitting.value) {
        return; // Prevent double submission
    }

    isSubmitting.value = true;

    const clientUuid = makeUuid();
    enqueue({
        key: clientUuid,
        url: route('prize.store'),
        payload: {
            client_uuid: clientUuid,
            prize_name: 'null', // Prize will be set later - using 'null' string
            event_id: props.event.id,
            location_id: props.location.id,
            winner_name: selectedWinner.value.first_name + " " + selectedWinner.value.last_name,
            winner_email: selectedWinner.value.email_address,
            winner_mobile_number: selectedWinner.value.mobile_number,
        },
        meta: {
            type: 'create-winner',
            client_uuid: clientUuid,
            event_id: props.event.id,
            location_id: props.location.id,
        },
    });

    showPick.value = false;

    const firstName = selectedWinner.value.first_name;
    if (connection.value === 'offline') {
        toast.success(`${firstName} saved on this phone`, 'It will send by itself once the internet is back. You can keep picking.');
    } else if (connection.value === 'weak') {
        toast.success(`${firstName} saved on this phone`, 'The internet is weak, so it is still sending. Keep this page open; you can keep picking.');
    } else {
        toast.success(`${firstName} saved as a winner`);
    }
    isSubmitting.value = false;
};

const pickAgain = () => {
    if (!eligibleAttendees.value.length) {
        toast.warning('Nobody left to draw', 'Everyone in the draw has already won, or nobody matches the filter.');
        return;
    }

    selectedPrize.value = null;
    selectedWinner.value = null;
    winnerDisplay.value = 'Searching for a winner...';
    isPicking.value = true;

    // Restart animation
    if (animationInterval) clearInterval(animationInterval);

    animationInterval = setInterval(() => {
        const randomIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        const randomAttendee = eligibleAttendees.value[randomIndex];
        winnerDisplay.value = `${randomAttendee.first_name} ${randomAttendee.last_name}`;
    }, 100);

    setTimeout(() => {
        clearInterval(animationInterval);
        isPicking.value = false;

        const finalIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        selectedWinner.value = eligibleAttendees.value[finalIndex];
        winnerDisplay.value = `${selectedWinner.value.first_name} ${selectedWinner.value.last_name}`;
    }, 500);
};


// ✅ Compute attendees who have NOT been picked as winners yet (only from current location)
// Uses mergedPrizes so locally saved (not-yet-synced) winners are excluded too.
const eligibleAttendees = computed(() => {
    return locationAttendees.value.filter(attendee => {
        const isWinner = mergedPrizes.value.some(prize => prize.winner_email === attendee.email_address);

        // Apply dynamic filters based on questions
        if (!hasActiveFilters.value) {
            return !isWinner;
        }

        const activeFilters = filters.value.filter(f => f.questionColumn && f.filterValue);
        
        if (activeFilters.length === 0) {
            return !isWinner;
        }

        let matchesFilter = true;

        if (filterCondition.value === 'AND') {
            // All filters must match
            matchesFilter = activeFilters.every(filter => {
                // Special handling for Date of Birth - calculate age from date
                if (isDateOfBirthQuestion(filter.questionColumn)) {
                    const dateOfBirth = attendee[filter.questionColumn];
                    if (!dateOfBirth) return false;
                    const age = calculateAge(dateOfBirth);
                    return ageMatchesRange(age, filter.filterValue);
                }
                
                // Regular field filtering
                const fieldValue = attendee[filter.questionColumn];
                if (fieldValue === null || fieldValue === undefined || fieldValue === '') {
                    return false;
                }
                
                // For dropdown fields with predefined options, use exact match
                // For text fields, use substring match
                if (questionHasOptions(filter.questionColumn)) {
                    // Exact match (case-insensitive) for dropdowns
                    return fieldValue.toString().toLowerCase().trim() === filter.filterValue.toLowerCase().trim();
                } else {
                    // Substring match (case-insensitive) for text fields
                    return fieldValue.toString().toLowerCase().includes(filter.filterValue.toLowerCase());
                }
            });
        } else {
            // OR: At least one filter must match
            matchesFilter = activeFilters.some(filter => {
                // Special handling for Date of Birth - calculate age from date
                if (isDateOfBirthQuestion(filter.questionColumn)) {
                    const dateOfBirth = attendee[filter.questionColumn];
                    if (!dateOfBirth) return false;
                    const age = calculateAge(dateOfBirth);
                    return ageMatchesRange(age, filter.filterValue);
                }
                
                // Regular field filtering
                const fieldValue = attendee[filter.questionColumn];
                if (fieldValue === null || fieldValue === undefined || fieldValue === '') {
                    return false;
                }
                
                // For dropdown fields with predefined options, use exact match
                // For text fields, use substring match
                if (questionHasOptions(filter.questionColumn)) {
                    // Exact match (case-insensitive) for dropdowns
                    return fieldValue.toString().toLowerCase().trim() === filter.filterValue.toLowerCase().trim();
                } else {
                    // Substring match (case-insensitive) for text fields
                    return fieldValue.toString().toLowerCase().includes(filter.filterValue.toLowerCase());
                }
            });
        }

        return !isWinner && matchesFilter;
    });
});

const prizeInput = ref(null);

// Add this new method to handle modal closing
const handleModalClose = () => {
    // Clear focus before closing
    if (document.activeElement instanceof HTMLElement) {
        document.activeElement.blur();
    }
    
    // Reset form values
    prizeForWinner.value = '';
    selectedPrize.value = null;
    
    showPrizeDetails.value = false;
};

/* ------------------------------------------------------------- display -- */
// A winner is saved the moment the draw is confirmed, with prize_name held as
// the string 'null' until the host names it. These read that, nothing more.

/** The prize's name, or '' while it is still waiting to be named. */
const prizeLabel = (prize) => {
    const name = prize?.prize_name;

    return !name || name === 'null' ? '' : name;
};

/** Drawn, but nobody has said what they won yet. */
const needsPrizeName = (prize) => !!prize?.winner && !prizeLabel(prize);

const drawnCount = computed(() => mergedPrizes.value.filter((p) => p.winner).length);
const unnamedCount = computed(() => mergedPrizes.value.filter(needsPrizeName).length);

/** Date and time of this screening, formatted for the header. */
const whenLabel = computed(() => formatLocationDateTime(props.location?.date, props.location?.time)
    || props.location?.date || '');

</script>
<style scoped>
/* The spin and the reveal. Everything else is utility classes. */
@keyframes paw-pulse {
    0%, 100% { transform: scale(1); opacity: .85; }
    50%      { transform: scale(1.09); opacity: 1; }
}
@keyframes paw-rise {
    from { transform: translateY(10px) scale(.97); opacity: 0; }
    to   { transform: none; opacity: 1; }
}
.paw-pulse { animation: paw-pulse 1.1s ease-in-out infinite; }
.paw-rise  { animation: paw-rise .34s cubic-bezier(.2, .9, .3, 1) both; }

@media (prefers-reduced-motion: reduce) {
    .paw-pulse, .paw-rise { animation: none; }
}

/* Dark form controls, without leaning on a framework's reset. */
.paw-field {
    background-color: #151515;
    border: 2px solid #2e2e2e;
    color: #fff;
    border-radius: 0.75rem;
}
.paw-field:focus {
    outline: 0;
    border-color: #16c3d9;
    box-shadow: none;
}
.paw-field::placeholder { color: #55595a; }

.paw-select {
    background-color: #1f1f1f;
    border: 1px solid #2e2e2e;
    color: #fff;
    border-radius: 0.625rem;
}
.paw-select:focus { outline: 0; border-color: #16c3d9; box-shadow: none; }
</style>

<template>
    <Head title="Pick a Winner" />

    <PickaWinnerLayout>
        <div class="mx-auto flex min-h-screen w-full max-w-[560px] flex-col bg-[#000000]">
            <!-- =========================================== header -- -->
            <header class="sticky top-0 z-20 flex min-h-[56px] items-center gap-2.5 border-b border-[#2e2e2e] bg-[#151515] px-[max(18px,env(safe-area-inset-left))] py-2.5">
                <div class="min-w-0 flex-1 leading-tight">
                    <b class="block truncate text-sm font-bold text-white">{{ location.name }}</b>
                    <span class="block truncate text-[11.5px] text-[#74797a]">{{ whenLabel }}</span>
                </div>

                <span
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[10.5px] font-bold"
                    :class="connection === 'online' ? 'bg-[#0d2419] text-[#3dd68c]'
                        : connection === 'weak' ? 'bg-[#2a1410] text-[#e85c41]'
                        : 'bg-[#2c1414] text-[#f77d7d]'"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                    {{ connection === 'online' ? 'Online' : connection === 'weak' ? 'Weak' : 'Offline' }}
                </span>

                <button
                    type="button" @click="showMenu = true"
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-[#2e2e2e] bg-[#1f1f1f] text-[#abb0b2] hover:text-white"
                    aria-label="Menu"
                >
                    <svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"><circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" /></svg>
                </button>
            </header>

            <main class="flex-1 px-[max(18px,env(safe-area-inset-left))] pb-[calc(28px+env(safe-area-inset-bottom))] pt-4">
                <!-- ================================ sync banner -- -->
                <!-- Winners are saved on the device first, so a bad signal
                     never blocks a draw. The host has to be able to see that
                     at a glance, or they will draw a second winner. -->
                <div
                    v-if="connection !== 'online' || pendingSyncCount > 0"
                    class="mb-3.5 rounded-xl px-4 py-3"
                    :class="connection === 'offline' ? 'bg-[#2c1414]' : connection === 'weak' ? 'bg-[#2a1410]' : 'bg-[#0d2419]'"
                >
                    <p
                        class="text-[13.5px] font-bold"
                        :class="connection === 'offline' ? 'text-[#f77d7d]' : connection === 'weak' ? 'text-[#e85c41]' : 'text-[#3dd68c]'"
                    >
                        <template v-if="connection === 'offline'">No internet — saving on this phone</template>
                        <template v-else-if="connection === 'weak'">Weak signal — still sending</template>
                        <template v-else>Sending {{ pendingSyncCount }} to the server…</template>
                    </p>
                    <p class="mt-1 text-[12.5px] text-[#abb0b2]">
                        <template v-if="connection === 'offline'">
                            {{ pendingSyncCount }} winner{{ pendingSyncCount === 1 ? '' : 's' }} saved here. They send
                            themselves once you have signal — keep drawing.
                        </template>
                        <template v-else-if="connection === 'weak'">
                            {{ pendingSyncCount }} waiting{{ syncDetail }}. Keep drawing and leave this page open.
                        </template>
                        <template v-else>Nothing is lost if you close the page.</template>
                    </p>
                    <p v-if="connection !== 'online'" class="mt-2 rounded-lg bg-black/25 px-3 py-2 text-[12.5px] text-white">
                        Write the winners down as well, just in case.
                    </p>
                </div>

                <!-- ======================================= logo -- -->
                <img
                    v-if="eventLogo" :src="eventLogo" :alt="event.event_name"
                    class="mx-auto mb-4 max-h-32 max-w-[260px] object-contain"
                    @error="logoBroken = true"
                />

                <!-- ===================================== counts -- -->
                <div class="mb-3.5 grid grid-cols-2 gap-2.5">
                    <div class="rounded-xl border border-[#2e2e2e] bg-[#151515] px-3.5 py-3">
                        <span class="text-[10.5px] font-bold uppercase tracking-[0.08em] text-[#74797a]">In the draw</span>
                        <span class="block font-mono text-2xl tracking-[-0.03em] text-white">{{ eligibleAttendees.length }}</span>
                        <span class="text-[11.5px] text-[#abb0b2]">of {{ locationAttendees.length }} signed up</span>
                    </div>
                    <div class="rounded-xl border border-[#2e2e2e] bg-[#151515] px-3.5 py-3">
                        <span class="text-[10.5px] font-bold uppercase tracking-[0.08em] text-[#74797a]">Drawn</span>
                        <span class="block font-mono text-2xl tracking-[-0.03em] text-white">{{ drawnCount }}</span>
                        <span class="text-[11.5px] text-[#abb0b2]">winner{{ drawnCount === 1 ? '' : 's' }} tonight</span>
                    </div>
                </div>

                <p v-if="hasActiveFilters" class="mb-3.5 rounded-xl bg-[#0b2a30] px-4 py-3 text-[12.5px] text-[#16c3d9]">
                    {{ getFilterExplanation }}
                </p>

                <!-- =================================== the draw -- -->
                <button
                    type="button" @click="openPickWinnerModal"
                    :disabled="!eligibleAttendees.length"
                    class="min-h-[68px] w-full rounded-2xl bg-[#16c3d9] text-lg font-extrabold text-[#00171c] disabled:opacity-40"
                >
                    {{ eligibleAttendees.length ? 'Pick a winner' : 'Nobody left to draw' }}
                </button>
                <p v-if="!eligibleAttendees.length" class="mt-2 text-center text-[12.5px] text-[#74797a]">
                    {{ locationAttendees.length ? 'Everyone in the draw has already won.' : 'Nobody has signed up at this screening yet.' }}
                </p>

                <button
                    type="button" @click="showFilters = !showFilters"
                    class="mt-2.5 min-h-[46px] w-full rounded-xl text-[14px] font-semibold text-[#abb0b2] hover:bg-[#1f1f1f] hover:text-white"
                >
                    {{ showFilters ? 'Hide filter' : 'Filter' }}
                </button>

                <!-- =================================== filters -- -->
                <!-- A prize that cannot go to everyone — alcohol, say. The
                     reason stays on the page while drawing, so the host can
                     answer "why wasn't I in it?". -->
                <div v-if="showFilters" class="mt-1 rounded-2xl border border-[#2e2e2e] bg-[#151515] p-4">
                    <p class="text-[13.5px] font-bold text-white">Filter</p>
                    <p class="mt-1 text-[12.5px] text-[#74797a]">
                        Built from the questions on your sign-up form. Leave it empty and everyone is in.
                    </p>

                    <div v-if="!availableQuestions.length" class="mt-3 text-[12.5px] text-[#74797a]">
                        This form has no questions to filter on.
                    </div>

                    <template v-else>
                        <div v-for="(filter, i) in filters" :key="i" class="mt-3 rounded-xl border border-[#2e2e2e] bg-[#1f1f1f] p-3">
                            <div class="grid gap-2">
                                <select
                                    v-model="filter.questionColumn" @change="updateQuestionText(i)"
                                    class="paw-select min-h-[46px] w-full px-3 text-[14px]"
                                >
                                    <option value="">Choose a question…</option>
                                    <option v-for="q in availableQuestions" :key="q.column_name" :value="q.column_name">
                                        {{ q.text }}
                                    </option>
                                </select>

                                <select
                                    v-if="filter.questionColumn && (questionHasOptions(filter.questionColumn) || isDateOfBirthQuestion(filter.questionColumn))"
                                    v-model="filter.filterValue"
                                    class="paw-select min-h-[46px] w-full px-3 text-[14px]"
                                >
                                    <option value="">Any answer</option>
                                    <option v-for="opt in getQuestionOptions(filter.questionColumn)" :key="opt" :value="opt">
                                        {{ opt }}
                                    </option>
                                </select>

                                <select
                                    v-else-if="filter.questionColumn"
                                    v-model="filter.filterValue"
                                    class="paw-select min-h-[46px] w-full px-3 text-[14px]"
                                >
                                    <option value="">Any answer</option>
                                    <option v-for="val in getUniqueValuesForQuestion(filter.questionColumn)" :key="val" :value="val">
                                        {{ val }}
                                    </option>
                                </select>
                            </div>

                            <button
                                type="button" @click="removeFilter(i)"
                                class="mt-2 min-h-[40px] w-full rounded-lg text-[13px] font-semibold text-[#f77d7d] hover:bg-[#2c1414]"
                            >
                                Remove this filter
                            </button>
                        </div>

                        <div v-if="filters.length > 1" class="mt-3 flex gap-2">
                            <button
                                v-for="mode in ['AND', 'OR']" :key="mode" type="button"
                                @click="filterCondition = mode"
                                class="min-h-[44px] flex-1 rounded-xl border text-[13px] font-bold"
                                :class="filterCondition === mode
                                    ? 'border-[#16c3d9] bg-[#0b2a30] text-[#16c3d9]'
                                    : 'border-[#2e2e2e] bg-[#1f1f1f] text-[#abb0b2]'"
                            >
                                {{ mode === 'AND' ? 'Match all' : 'Match any' }}
                            </button>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <button
                                type="button" @click="addFilter"
                                class="min-h-[46px] flex-1 rounded-xl border border-[#2e2e2e] bg-[#1f1f1f] text-[13.5px] font-bold text-white hover:bg-[#2b2b2b]"
                            >
                                + Add a filter
                            </button>
                            <button
                                v-if="filters.length" type="button" @click="resetFilters"
                                class="min-h-[46px] rounded-xl px-4 text-[13.5px] font-semibold text-[#abb0b2] hover:bg-[#1f1f1f]"
                            >
                                Clear
                            </button>
                        </div>

                        <p class="mt-3 font-mono text-[12.5px] text-[#74797a]">
                            {{ eligibleAttendees.length }} in the draw with these settings
                        </p>
                    </template>
                </div>

                <!-- ============================ prizes/winners -- -->
                <div class="mt-5">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="text-[11.5px] font-bold uppercase tracking-[0.1em] text-[#74797a]">
                            Winners tonight
                        </h2>
                        <span v-if="unnamedCount" class="text-[11.5px] font-bold text-[#16c3d9]">
                            {{ unnamedCount }} need{{ unnamedCount === 1 ? 's' : '' }} a prize name
                        </span>
                    </div>

                    <div v-if="mergedPrizes.length" class="mt-2 overflow-hidden rounded-2xl border border-[#2e2e2e] bg-[#151515]">
                        <div
                            v-for="(prize, i) in mergedPrizes" :key="prize.client_uuid || prize.id || i"
                            class="border-b border-[#2e2e2e] px-4 py-3 last:border-b-0"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full font-mono text-[12px]"
                                    :class="prize.winner ? 'bg-[#0d2419] text-[#3dd68c]' : 'bg-[#2b2b2b] text-[#74797a]'"
                                >{{ prize.winner ? '✓' : i + 1 }}</span>

                                <div class="min-w-0 flex-1">
                                    <b v-if="prize.winner" class="block truncate text-[15px] font-bold text-white">
                                        {{ prize.winner }}
                                    </b>
                                    <b v-else class="block truncate text-[15px] font-bold text-[#abb0b2]">
                                        {{ prizeLabel(prize) || 'Prize ' + (i + 1) }}
                                    </b>

                                    <span class="block truncate text-[12.5px]">
                                        <template v-if="needsPrizeName(prize)">
                                            <span class="font-semibold text-[#16c3d9]">Tap to say what they won</span>
                                        </template>
                                        <template v-else-if="prize.winner">
                                            <span class="text-[#abb0b2]">{{ prizeLabel(prize) }}</span>
                                        </template>
                                        <template v-else>
                                            <span class="text-[#74797a]">No winner — left over, safe to delete</span>
                                        </template>
                                    </span>

                                    <span
                                        v-if="prize.__pending"
                                        class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-[#2a1410] px-2 py-0.5 text-[10.5px] font-bold text-[#e85c41]"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>Saved on this phone
                                    </span>
                                </div>
                            </div>

                            <div class="mt-2.5 flex gap-2">
                                <button
                                    v-if="needsPrizeName(prize)" type="button" @click="openPrizeDetailsModal(prize)"
                                    class="min-h-[42px] flex-1 rounded-xl bg-[#16c3d9] text-[13.5px] font-bold text-[#00171c]"
                                >
                                    Name the prize
                                </button>
                                <button
                                    v-else-if="prize.winner" type="button" @click="openPrizeDetailsModal(prize)"
                                    class="min-h-[42px] flex-1 rounded-xl border border-[#2e2e2e] bg-[#1f1f1f] text-[13.5px] font-semibold text-white hover:bg-[#2b2b2b]"
                                >
                                    Change the prize
                                </button>
                                <button
                                    type="button" @click="destroy(prize)"
                                    class="min-h-[42px] rounded-xl border border-[#2e2e2e] px-4 text-[13.5px] font-semibold text-[#f77d7d] hover:border-[#f77d7d] hover:bg-[#2c1414]"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>

                    <p v-else class="mt-2 rounded-2xl border border-dashed border-[#2e2e2e] px-4 py-8 text-center text-[13px] text-[#74797a]">
                        No winners yet. Tap Pick a winner to draw the first.
                    </p>

                </div>
            </main>
        </div>

        <!-- ============================================================
             Panels. Held in state, so the winner's name can fill a phone
             screen instead of sitting inside a dialog box.
             ============================================================ -->

        <!-- --------------------------------------- the draw -- -->
        <Teleport to="body">
            <div v-if="showPick" class="fixed inset-0 z-[70] flex flex-col overflow-y-auto bg-[#000000] px-[max(18px,env(safe-area-inset-left))] pb-[calc(24px+env(safe-area-inset-bottom))] pt-[calc(18px+env(safe-area-inset-top))]">
                <div class="mx-auto flex w-full max-w-[560px] flex-1 flex-col">
                    <!-- spinning -->
                    <div v-if="isPicking" class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                        <span class="paw-pulse grid h-14 w-14 place-items-center rounded-full bg-[#0b2a30] text-[#16c3d9]">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 4h8v4a4 4 0 0 1-8 0V4zM8 5H5a3 3 0 0 0 3 3M16 5h3a3 3 0 0 1-3 3M10 12v3h4v-3M8 20h8" /></svg>
                        </span>
                        <p class="text-[11.5px] font-bold uppercase tracking-[0.12em] text-[#74797a]">Drawing</p>
                        <p class="min-h-[1.1em] text-[clamp(27px,8.5vw,38px)] font-extrabold tracking-[-0.03em] text-[#abb0b2]">
                            {{ winnerDisplay }}
                        </p>
                        <p class="text-[12.5px] text-[#74797a]">{{ eligibleAttendees.length }} in the draw</p>
                    </div>

                    <!-- settled -->
                    <template v-else>
                        <div class="paw-rise flex flex-1 flex-col items-center justify-center gap-3 py-5 text-center">
                            <img
                                v-if="eventLogo" :src="eventLogo" :alt="event.event_name"
                                class="max-h-20 max-w-[220px] object-contain" @error="logoBroken = true"
                            />
                            <span v-else class="grid h-14 w-14 place-items-center rounded-full bg-[#0b2a30] text-[#16c3d9]">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 4h8v4a4 4 0 0 1-8 0V4zM8 5H5a3 3 0 0 0 3 3M16 5h3a3 3 0 0 1-3 3M10 12v3h4v-3M8 20h8" /></svg>
                            </span>
                            <p class="text-[11.5px] font-bold uppercase tracking-[0.12em] text-[#74797a]">Tonight's winner</p>
                            <h2 class="text-[clamp(34px,11vw,52px)] font-black leading-[1.02] tracking-[-0.035em] text-[#16c3d9]">
                                {{ winnerDisplay }}
                            </h2>
                            <!-- Who was drawn, so the host can check they have the
                                 right person before saving. Empty rows are left out. -->
                            <dl v-if="winnerDetails.length" class="mt-2 w-full max-w-[420px] divide-y divide-[#242424] rounded-2xl border border-[#2e2e2e] bg-[#151515] px-4 text-left">
                                <div v-for="row in winnerDetails" :key="row.label" class="flex items-baseline justify-between gap-4 py-2.5">
                                    <dt class="shrink-0 text-[11.5px] font-bold uppercase tracking-[0.1em] text-[#74797a]">{{ row.label }}</dt>
                                    <dd class="min-w-0 text-right text-[15px] font-semibold text-white [overflow-wrap:anywhere]">{{ row.value }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="grid gap-2.5">
                            <button
                                type="button" @click="confirmWinner" :disabled="isSubmitting || !selectedWinner"
                                class="min-h-[68px] w-full rounded-2xl bg-[#16c3d9] text-lg font-extrabold text-[#00171c] disabled:opacity-40"
                            >
                                {{ isSubmitting ? 'Saving…' : 'Save this winner' }}
                            </button>
                            <button
                                type="button" @click="pickAgain"
                                class="min-h-[54px] w-full rounded-2xl border border-[#2e2e2e] text-[15.5px] font-bold text-[#f77d7d] hover:border-[#f77d7d] hover:bg-[#2c1414]"
                            >
                                Not here — draw again
                            </button>
                            <button
                                type="button" @click="showPick = false"
                                class="min-h-[46px] w-full rounded-xl text-[14px] font-semibold text-[#74797a] hover:bg-[#1f1f1f] hover:text-white"
                            >
                                Cancel
                            </button>
                            <p class="text-center text-[12.5px] text-[#74797a]">
                                Nothing is saved until you tap save, so drawing again costs nothing.
                            </p>
                        </div>
                    </template>
                </div>
            </div>
        </Teleport>

        <!-- --------------------------------- delete a winner -- -->
        <Teleport to="body">
            <div v-if="deleteTarget" class="fixed inset-0 z-[72] bg-black/75"></div>
            <div
                v-if="deleteTarget" role="dialog" aria-modal="true" aria-label="Delete this winner"
                class="fixed inset-x-0 bottom-0 z-[73] mx-auto grid max-h-[86vh] w-full max-w-[560px] gap-3 overflow-y-auto rounded-t-2xl border-t border-[#2e2e2e] bg-[#151515] px-[max(18px,env(safe-area-inset-left))] pb-[calc(20px+env(safe-area-inset-bottom))] pt-3.5"
            >
                <div class="relative pr-12">
                    <button type="button" @click="cancelDelete" aria-label="Close" class="absolute right-0 top-0 grid h-9 w-9 place-items-center rounded-lg border border-[#2e2e2e] bg-[#1f1f1f] text-[#abb0b2] hover:text-white"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
                    <p class="text-[11.5px] font-bold uppercase tracking-[0.1em] text-[#f77d7d]">Delete</p>
                    <h3 class="text-xl font-extrabold tracking-[-0.02em] text-white">
                        {{ deleteTarget.winner || prizeLabel(deleteTarget) || 'This prize' }}
                    </h3>
                    <p class="mt-1 text-[13.5px] leading-snug text-[#abb0b2]">
                        <template v-if="deleteIsLocalOnly">
                            This winner is only saved on this phone so far. Removing it discards the winner.
                        </template>
                        <template v-else-if="deleteTarget.winner">
                            This cannot be undone. They go back into the draw and can be picked again.
                        </template>
                        <template v-else>
                            This cannot be undone. The prize is removed from the list.
                        </template>
                    </p>
                </div>

                <label v-if="!deleteIsLocalOnly" class="grid gap-2">
                    <span class="text-[12.5px] font-bold text-[#abb0b2]">Type DELETE to confirm</span>
                    <input
                        ref="deleteBox" v-model="deleteText" type="text" placeholder="DELETE"
                        autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false"
                        class="paw-field min-h-[60px] w-full px-4 font-mono text-[16.5px] font-semibold uppercase tracking-[0.12em]"
                        @keydown.enter.prevent="confirmDelete"
                    />
                </label>

                <button
                    type="button" @click="confirmDelete" :disabled="!deleteReady"
                    class="min-h-[60px] w-full rounded-2xl bg-[#f77d7d] text-base font-extrabold text-[#2c0b0b] disabled:opacity-40"
                >
                    {{ deleteIsLocalOnly ? 'Remove this winner' : 'Delete' }}
                </button>
                <button
                    type="button" @click="cancelDelete"
                    class="min-h-[46px] w-full rounded-xl text-[14px] font-semibold text-[#abb0b2] hover:bg-[#1f1f1f] hover:text-white"
                >
                    Cancel
                </button>
            </div>
        </Teleport>

        <!-- ------------------------------- name the prize -- -->
        <Teleport to="body">
            <div v-if="showPrizeDetails" class="fixed inset-0 z-[72] bg-black/75"></div>
            <div
                v-if="showPrizeDetails"
                class="fixed inset-x-0 bottom-0 z-[73] mx-auto grid max-h-[86vh] w-full max-w-[560px] gap-3 overflow-y-auto rounded-t-2xl border-t border-[#2e2e2e] bg-[#151515] px-[max(18px,env(safe-area-inset-left))] pb-[calc(20px+env(safe-area-inset-bottom))] pt-3.5"
            >
                <div class="relative pr-12">
                    <button type="button" @click="handleModalClose" aria-label="Close" class="absolute right-0 top-0 grid h-9 w-9 place-items-center rounded-lg border border-[#2e2e2e] bg-[#1f1f1f] text-[#abb0b2] hover:text-white"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
                    <p class="text-[11.5px] font-bold uppercase tracking-[0.1em] text-[#74797a]">Winner</p>
                    <h3 class="text-xl font-extrabold tracking-[-0.02em] text-white">{{ selectedPrize?.winner }}</h3>
                </div>

                <dl v-if="prizeWinnerDetails.length" class="divide-y divide-[#242424] rounded-xl border border-[#2e2e2e] bg-[#1b1b1b] px-3.5">
                    <div v-for="row in prizeWinnerDetails" :key="row.label" class="flex items-baseline justify-between gap-4 py-2">
                        <dt class="shrink-0 text-[11px] font-bold uppercase tracking-[0.1em] text-[#74797a]">{{ row.label }}</dt>
                        <dd class="min-w-0 text-right text-[14px] font-semibold text-white [overflow-wrap:anywhere]">{{ row.value }}</dd>
                    </div>
                </dl>

                <label class="grid gap-2">
                    <span class="text-[12.5px] font-bold text-[#abb0b2]">What did they win?</span>
                    <input
                        ref="prizeInput" v-model="prizeForWinner" type="text" placeholder="Type the prize"
                        class="paw-field min-h-[60px] w-full px-4 text-[16.5px] font-semibold"
                        @keydown.enter.prevent="updatePrizeForWinner"
                    />
                </label>

                <button
                    type="button" @click="updatePrizeForWinner" :disabled="!prizeForWinner.trim()"
                    class="min-h-[60px] w-full rounded-2xl bg-[#16c3d9] text-base font-extrabold text-[#00171c] disabled:opacity-40"
                >
                    Save the prize
                </button>
                <button
                    type="button" @click="handleModalClose"
                    class="min-h-[46px] w-full rounded-xl text-[14px] font-semibold text-[#abb0b2] hover:bg-[#1f1f1f] hover:text-white"
                >
                    Cancel
                </button>
            </div>
        </Teleport>

        <!-- ------------------------------------------ menu -- -->
        <Teleport to="body">
            <div v-if="showMenu" class="fixed inset-0 z-[72] bg-black/75" @click="showMenu = false"></div>
            <div
                v-if="showMenu"
                class="fixed inset-x-0 bottom-0 z-[73] mx-auto grid max-h-[86vh] w-full max-w-[560px] gap-2.5 overflow-y-auto rounded-t-2xl border-t border-[#2e2e2e] bg-[#151515] px-[max(18px,env(safe-area-inset-left))] pb-[calc(20px+env(safe-area-inset-bottom))] pt-3.5"
            >
                <span class="mx-auto h-1 w-9 rounded-full bg-[#2e2e2e]"></span>
                <div>
                    <h3 class="text-lg font-extrabold tracking-[-0.02em] text-white">{{ location.name }}</h3>
                    <p class="text-[12.5px] text-[#74797a]">
                        {{ whenLabel }} · {{ locationAttendees.length }} signed up
                    </p>
                </div>

                <button
                    type="button" @click="showMenu = false; openInstructions()"
                    class="min-h-[52px] w-full rounded-xl border border-[#2e2e2e] bg-[#1f1f1f] px-4 text-left text-[14.5px] font-bold text-white hover:bg-[#2b2b2b]"
                >
                    How to pick a winner
                </button>

                <p v-if="pendingSyncCount" class="rounded-xl bg-[#2a1410] px-4 py-3 text-[12.5px] text-[#e85c41]">
                    {{ pendingSyncCount }} winner{{ pendingSyncCount === 1 ? '' : 's' }} still sending{{ syncDetail }}.
                    Leave this page open until it clears.
                </p>

                <a
                    :href="route('pickawinner.index')"
                    class="min-h-[52px] w-full rounded-xl px-4 text-left text-[14.5px] font-semibold leading-[52px] text-[#abb0b2] hover:bg-[#1f1f1f] hover:text-white"
                >
                    Leave this draw
                </a>
            </div>
        </Teleport>

        <!-- ------------------------- how to pick a winner -- -->
        <Teleport to="body">
            <div v-if="showHowTo" class="fixed inset-0 z-[74] overflow-y-auto bg-[#000000] px-[max(18px,env(safe-area-inset-left))] pb-[calc(24px+env(safe-area-inset-bottom))] pt-[calc(18px+env(safe-area-inset-top))]">
                <div class="mx-auto w-full max-w-[560px]">
                    <button
                        type="button" @click="showHowTo = false"
                        class="mb-3 inline-flex min-h-[44px] items-center gap-2 rounded-xl border border-[#2e2e2e] bg-[#1f1f1f] px-4 text-[13.5px] font-bold text-white"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m15 6-6 6 6 6" /></svg>
                        Back to the draw
                    </button>
                    <HowToPickAWinner :event="event" :in-draw="true" />
                </div>
            </div>
        </Teleport>
    </PickaWinnerLayout>
</template>
