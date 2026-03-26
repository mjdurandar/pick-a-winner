<template>
    <div class="scroll-date-picker">
        <div class="picker-display form-control w-full border rounded px-3 py-2" @click="togglePicker" ref="displayRef">
            <span v-if="displayValue">{{ displayValue }}</span>
            <span v-else class="placeholder">mm/dd/yyyy</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="picker-icon">
                <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/>
            </svg>
        </div>

        <div v-if="isOpen" class="picker-dropdown" ref="dropdownRef">
            <div class="picker-header">
                <button type="button" class="clear-btn" @click="clearDate">Clear</button>
            </div>
            <div class="picker-columns">
                <div class="picker-column month-column" ref="monthCol">
                    <div class="picker-scroll" @scroll="onMonthScroll" ref="monthScroll">
                        <div class="picker-spacer"></div>
                        <div
                            v-for="(m, index) in months"
                            :key="index"
                            class="picker-item"
                            :class="{ selected: index === selectedMonth }"
                            @click="selectMonth(index)"
                        >
                            {{ m }}
                        </div>
                        <div class="picker-spacer"></div>
                    </div>
                </div>
                <div class="picker-column" ref="dayCol">
                    <div class="picker-scroll" @scroll="onDayScroll" ref="dayScroll">
                        <div class="picker-spacer"></div>
                        <div
                            v-for="d in days"
                            :key="d"
                            class="picker-item"
                            :class="{ selected: d === selectedDay }"
                            @click="selectDay(d)"
                        >
                            {{ d }}
                        </div>
                        <div class="picker-spacer"></div>
                    </div>
                </div>
                <div class="picker-column" ref="yearCol">
                    <div class="picker-scroll" @scroll="onYearScroll" ref="yearScroll">
                        <div class="picker-spacer"></div>
                        <div
                            v-for="y in years"
                            :key="y"
                            class="picker-item"
                            :class="{ selected: y === selectedYear }"
                            @click="selectYear(y)"
                        >
                            {{ y }}
                        </div>
                        <div class="picker-spacer"></div>
                    </div>
                </div>
                <div class="picker-highlight"></div>
            </div>
            <div class="picker-footer">
                <button type="button" class="done-btn" @click="confirmDate">Done</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    minYear: {
        type: Number,
        default: 1920
    },
    maxYear: {
        type: Number,
        default: null
    }
});

const emit = defineEmits(['update:modelValue']);

const months = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

const currentYear = new Date().getFullYear();
const yearStart = props.minYear;
const yearEnd = props.maxYear ?? currentYear;
const years = Array.from({ length: yearEnd - yearStart + 1 }, (_, i) => yearStart + i);

const isOpen = ref(false);
const selectedDay = ref(1);
const selectedMonth = ref(0);
const selectedYear = ref(currentYear);

const dayScroll = ref(null);
const monthScroll = ref(null);
const yearScroll = ref(null);
const displayRef = ref(null);
const dropdownRef = ref(null);

const ITEM_HEIGHT = 40;

const daysInMonth = computed(() => {
    return new Date(selectedYear.value, selectedMonth.value + 1, 0).getDate();
});

const days = computed(() => {
    return Array.from({ length: daysInMonth.value }, (_, i) => i + 1);
});

// Clamp day when month/year changes
watch([daysInMonth], () => {
    if (selectedDay.value > daysInMonth.value) {
        selectedDay.value = daysInMonth.value;
    }
});

const displayValue = computed(() => {
    if (!props.modelValue) return '';
    const date = new Date(props.modelValue + 'T00:00:00');
    if (isNaN(date.getTime())) return props.modelValue;
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${mm}/${dd}/${date.getFullYear()}`;
});

// Parse initial value
function parseModelValue() {
    if (props.modelValue) {
        const date = new Date(props.modelValue + 'T00:00:00');
        if (!isNaN(date.getTime())) {
            selectedDay.value = date.getDate();
            selectedMonth.value = date.getMonth();
            selectedYear.value = date.getFullYear();
        }
    } else {
        const today = new Date();
        selectedDay.value = today.getDate();
        selectedMonth.value = today.getMonth();
        selectedYear.value = today.getFullYear();
    }
}

watch(() => props.modelValue, parseModelValue);

function togglePicker() {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        parseModelValue();
        nextTick(() => {
            scrollToSelected();
        });
    }
}

function scrollToSelected() {
    if (dayScroll.value) {
        dayScroll.value.scrollTop = (selectedDay.value - 1) * ITEM_HEIGHT;
    }
    if (monthScroll.value) {
        monthScroll.value.scrollTop = selectedMonth.value * ITEM_HEIGHT;
    }
    if (yearScroll.value) {
        const yearIndex = years.indexOf(selectedYear.value);
        if (yearIndex >= 0) {
            yearScroll.value.scrollTop = yearIndex * ITEM_HEIGHT;
        }
    }
}

function getSnappedIndex(scrollTop) {
    return Math.round(scrollTop / ITEM_HEIGHT);
}

let dayScrollTimer = null;
function onDayScroll() {
    clearTimeout(dayScrollTimer);
    dayScrollTimer = setTimeout(() => {
        if (!dayScroll.value) return;
        const index = getSnappedIndex(dayScroll.value.scrollTop);
        const clamped = Math.max(0, Math.min(index, days.value.length - 1));
        selectedDay.value = days.value[clamped];
        dayScroll.value.scrollTo({ top: clamped * ITEM_HEIGHT, behavior: 'smooth' });
    }, 100);
}

let monthScrollTimer = null;
function onMonthScroll() {
    clearTimeout(monthScrollTimer);
    monthScrollTimer = setTimeout(() => {
        if (!monthScroll.value) return;
        const index = getSnappedIndex(monthScroll.value.scrollTop);
        const clamped = Math.max(0, Math.min(index, months.length - 1));
        selectedMonth.value = clamped;
        monthScroll.value.scrollTo({ top: clamped * ITEM_HEIGHT, behavior: 'smooth' });
    }, 100);
}

let yearScrollTimer = null;
function onYearScroll() {
    clearTimeout(yearScrollTimer);
    yearScrollTimer = setTimeout(() => {
        if (!yearScroll.value) return;
        const index = getSnappedIndex(yearScroll.value.scrollTop);
        const clamped = Math.max(0, Math.min(index, years.length - 1));
        selectedYear.value = years[clamped];
        yearScroll.value.scrollTo({ top: clamped * ITEM_HEIGHT, behavior: 'smooth' });
    }, 100);
}

function selectDay(d) {
    selectedDay.value = d;
    if (dayScroll.value) {
        dayScroll.value.scrollTo({ top: (d - 1) * ITEM_HEIGHT, behavior: 'smooth' });
    }
}

function selectMonth(index) {
    selectedMonth.value = index;
    if (monthScroll.value) {
        monthScroll.value.scrollTo({ top: index * ITEM_HEIGHT, behavior: 'smooth' });
    }
}

function selectYear(y) {
    selectedYear.value = y;
    if (yearScroll.value) {
        const yearIndex = years.indexOf(y);
        yearScroll.value.scrollTo({ top: yearIndex * ITEM_HEIGHT, behavior: 'smooth' });
    }
}

function confirmDate() {
    const day = String(selectedDay.value).padStart(2, '0');
    const month = String(selectedMonth.value + 1).padStart(2, '0');
    const dateStr = `${selectedYear.value}-${month}-${day}`;
    emit('update:modelValue', dateStr);
    isOpen.value = false;
}

function clearDate() {
    emit('update:modelValue', '');
    isOpen.value = false;
}

// Close on outside click
function handleClickOutside(e) {
    if (
        dropdownRef.value && !dropdownRef.value.contains(e.target) &&
        displayRef.value && !displayRef.value.contains(e.target)
    ) {
        confirmDate();
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<style scoped>
.scroll-date-picker {
    position: relative;
    width: 100%;
}

.picker-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    background: #fff !important;
}

.picker-display .placeholder {
    color: #6c757d;
    background: transparent;
    font-weight: 600;
}

.picker-display span {
    background: transparent;
}

.picker-icon {
    color: #6c757d;
    flex-shrink: 0;
    width: 16px;
    height: 16px;
}

.picker-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    z-index: 1055;
    background: #f2f2f7;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    width: 300px;
    overflow: hidden;
    user-select: none;
    padding-bottom: 8px;
}

.picker-header {
    display: flex;
    justify-content: flex-end;
    padding: 8px 12px 4px;
}

.clear-btn {
    background: none;
    border: none;
    color: #007aff;
    font-size: 16px;
    cursor: pointer;
    padding: 4px 8px;
}

.clear-btn:hover {
    opacity: 0.7;
}

.picker-columns {
    display: flex;
    position: relative;
    height: 200px;
}

.picker-column {
    flex: 1;
    position: relative;
    overflow: hidden;
}

.month-column {
    flex: 1.5;
}

.picker-scroll {
    height: 100%;
    overflow-y: auto;
    scroll-snap-type: y mandatory;
    -ms-overflow-style: none;
    scrollbar-width: none;
}

.picker-scroll::-webkit-scrollbar {
    display: none;
}

.picker-spacer {
    height: 80px;
}

.picker-item {
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #8e8e93;
    cursor: pointer;
    scroll-snap-align: center;
    transition: all 0.15s ease;
}

.picker-item.selected {
    font-size: 22px;
    font-weight: 600;
    color: #000;
}

.picker-item:hover:not(.selected) {
    color: #3a3a3c;
}

.picker-footer {
    display: flex;
    justify-content: center;
    padding: 8px 12px;
}

.done-btn {
    background: #007aff;
    border: none;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    padding: 8px 32px;
    border-radius: 8px;
    width: 100%;
}

.done-btn:hover {
    opacity: 0.85;
}

.picker-highlight {
    position: absolute;
    top: 50%;
    left: 8px;
    right: 8px;
    height: 40px;
    transform: translateY(-50%);
    background: rgba(120, 120, 128, 0.12);
    border-radius: 8px;
    pointer-events: none;
}
</style>
