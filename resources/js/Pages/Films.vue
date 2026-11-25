<script setup>
/**
 * Websites — the film sites a tour publishes to, one card each.
 *
 * Laid out like the Films page so the two read as a pair: a picture, the name,
 * what hangs off it, and the actions underneath. The picture is optional and
 * only there so a website can be recognised at a glance.
 */
import { ref, computed, watch, onUnmounted } from 'vue';
import { useForm, router, usePage, Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import Swal from 'sweetalert2';

const props = defineProps({
    films: { type: Array, default: () => [] },
    // Mirrored from FilmsController so an oversized file is refused on
    // selection rather than after a slow upload.
    maxImageKb: { type: Number, default: 2048 },
});

const page = usePage();
const userRole = computed(() => page?.props?.auth?.user?.role ?? null);
const userEmail = computed(() => page?.props?.auth?.user?.email ?? '');
const isAdmin = computed(() => userRole.value === 'admin');
const canDelete = computed(() => isAdmin.value && userEmail.value === 'mj@adventureentertainment.com');

/* ------------------------------------------------------------------ list -- */

const search = ref('');

const visibleFilms = computed(() => {
    const q = search.value.trim().toLowerCase();

    return q ? props.films.filter((f) => String(f.name).toLowerCase().includes(q)) : props.films;
});

// Shown when a website has no picture of its own, and swapped in if the one it
// has fails to load.
const DEFAULT_IMAGE = '/images/default-website-image.svg';

const useDefaultImage = (event) => {
    if (! event.target.src.endsWith(DEFAULT_IMAGE)) {
        event.target.src = DEFAULT_IMAGE;
    }
};

/* ------------------------------------------------------------------ form -- */

const isEditing = ref(false);
const showForm = ref(false);

const form = useForm({
    id: null,
    name: '',
    image: null,          // File, only when a new one is chosen
    remove_image: false,
});

const existingImage = ref(null);   // URL of the picture already saved
const imagePreview = ref(null);    // Object URL of the file just chosen
const fileError = ref(null);

const maxImageMb = computed(() => (props.maxImageKb / 1024).toFixed(1).replace(/\.0$/, ''));

const sizeLabel = (bytes) => bytes >= 1048576
    ? `${(bytes / 1048576).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`;

const setPreview = (file) => {
    if (imagePreview.value) URL.revokeObjectURL(imagePreview.value);
    imagePreview.value = file ? URL.createObjectURL(file) : null;
};

const clearFileInput = () => {
    const input = document.getElementById('website_image');
    if (input) input.value = '';
};

/** What the form shows: the new file, else the saved picture, else the default. */
const previewSrc = computed(() =>
    imagePreview.value || (form.remove_image ? null : existingImage.value) || DEFAULT_IMAGE);

const hasOwnImage = computed(() => !!imagePreview.value || (!!existingImage.value && !form.remove_image));

function handleImageChange(event) {
    const file = event.target.files[0] || null;
    fileError.value = null;

    if (file && file.size > props.maxImageKb * 1024) {
        fileError.value = `That image is ${sizeLabel(file.size)}. The limit is ${maxImageMb.value} MB — try resizing or exporting it at a lower quality.`;
        event.target.value = '';
        form.image = null;
        setPreview(null);

        return;
    }

    form.image = file;
    form.remove_image = false;
    setPreview(file);
}

/** Back to the default picture. The saved file is not deleted, only unlinked. */
function removeImage() {
    form.image = null;
    form.remove_image = !!existingImage.value;
    fileError.value = null;
    setPreview(null);
    clearFileInput();
}

function resetForm() {
    form.reset();
    form.clearErrors();
    existingImage.value = null;
    fileError.value = null;
    setPreview(null);
    clearFileInput();
}

function openCreateModal() {
    isEditing.value = false;
    resetForm();
    showForm.value = true;
}

function openEditModal(brand) {
    isEditing.value = true;
    resetForm();
    form.id = brand.id;
    form.name = brand.name;
    existingImage.value = brand.image_url;
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    resetForm();
}

const firstError = (errors, fallback) =>
    Object.values(errors ?? {}).find((m) => typeof m === 'string' && m) || fallback;

function saveBrand() {
    // A file can only travel as multipart form data, and PHP only reads that on
    // a POST — so an edit goes as POST with the method spoofed to PATCH.
    const payload = (data) => {
        const out = { name: data.name };
        if (data.image) out.image = data.image;
        if (isEditing.value) {
            out._method = 'PATCH';
            if (data.remove_image) out.remove_image = 1;
        }

        return out;
    };

    const url = isEditing.value
        ? route('films.update', { film: form.id })
        : route('films.store');

    form.transform(payload).post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            const done = isEditing.value ? 'Website has been updated.' : 'Website has been created.';
            closeForm();
            Swal.fire(isEditing.value ? 'Updated!' : 'Created!', done, 'success');
        },
        onError: (errors) => Swal.fire(
            'Error!',
            firstError(errors, `There was an issue ${isEditing.value ? 'updating' : 'creating'} the website.`),
            'error',
        ),
    });
}

// Show flash error in SweetAlert when landing on this page (e.g. after redirect from failed delete)
watch(
    () => page.props.flash?.error,
    (msg) => {
        if (msg) Swal.fire('Cannot delete', msg, 'error');
    },
    { immediate: true }
);

function deleteBrand(brand) {
    Swal.fire({
        title: `Delete ${brand.name}?`,
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#c42626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('films.destroy', { film: brand.id }));
        }
    });
}

onUnmounted(() => setPreview(null));
</script>

<template>
    <Head title="Websites" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold text-gray-500">
                        {{ films.length }} {{ films.length === 1 ? 'website' : 'websites' }}
                    </p>
                    <h2 class="truncate text-2xl font-extrabold tracking-tight text-gray-900">Websites</h2>
                </div>
                <button
                    v-if="isAdmin"
                    type="button" @click="openCreateModal"
                    class="h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105"
                >
                    New website
                </button>
            </div>
        </template>

        <div class="w-full px-4 py-6 sm:px-6 lg:px-8">
            <!-- Nothing yet -->
            <div v-if="!films.length" class="rounded-xl bg-white px-6 py-16 text-center shadow-card">
                <p class="text-base font-semibold text-gray-700">No websites yet.</p>
                <p class="mt-1 text-sm text-gray-500">A website is the film site a tour publishes to.</p>
                <button
                    v-if="isAdmin"
                    type="button" @click="openCreateModal"
                    class="mt-5 h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105"
                >
                    New website
                </button>
            </div>

            <template v-else>
                <!-- Only worth the room once there are more than a screenful. -->
                <div v-if="films.length > 8" class="mb-5 flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 shadow-card">
                    <input
                        v-model="search" type="search" placeholder="Search websites" aria-label="Search websites"
                        class="h-10 min-w-[200px] flex-1 rounded-lg border-gray-200 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9] sm:max-w-sm"
                    />
                    <p class="text-sm text-gray-500">
                        {{ visibleFilms.length === films.length ? `${films.length} shown` : `${visibleFilms.length} of ${films.length} shown` }}
                    </p>
                </div>

                <p v-if="!visibleFilms.length" class="rounded-xl bg-white px-6 py-14 text-center text-sm text-gray-500 shadow-card">
                    No website matches “{{ search }}”.
                </p>

                <!-- The websites, one card each -->
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    <article
                        v-for="brand in visibleFilms" :key="brand.id"
                        class="flex min-w-0 flex-col rounded-xl bg-white shadow-card"
                    >
                        <img
                            :src="brand.image_url || DEFAULT_IMAGE"
                            @error="useDefaultImage"
                            :alt="`${brand.name} website`"
                            class="h-40 w-full rounded-t-xl object-cover"
                        />

                        <div class="flex flex-1 flex-col gap-3 p-4">
                            <div class="min-w-0">
                                <h3 class="truncate text-base font-extrabold tracking-tight text-gray-900">{{ brand.name }}</h3>
                                <p class="text-xs text-gray-500">
                                    {{ brand.events_count }} film{{ brand.events_count === 1 ? '' : 's' }} on this website
                                </p>
                            </div>

                            <div v-if="isAdmin" class="mt-auto flex items-center gap-2 pt-1">
                                <button
                                    type="button" @click="openEditModal(brand)"
                                    class="h-10 flex-1 rounded-lg bg-[#16C3D9] px-3 text-sm font-bold text-[#06242A] hover:brightness-105"
                                >Edit</button>
                                <button
                                    v-if="canDelete"
                                    type="button" @click="deleteBrand(brand)"
                                    class="h-10 rounded-lg border border-gray-200 px-3 text-sm font-semibold text-red-700 hover:border-red-300 hover:bg-red-50"
                                >Delete</button>
                            </div>
                        </div>
                    </article>
                </div>
            </template>
        </div>

        <!-- Create / edit a website -->
        <Modal :show="showForm" max-width="md" :title="isEditing ? 'Edit website' : 'New website'" @close="closeForm">
            <form id="website-form" @submit.prevent="saveBrand" class="space-y-5 p-6">
                <label class="block">
                    <span class="text-xs font-bold text-gray-600">Website name</span>
                    <input
                        v-model="form.name" type="text" required maxlength="255"
                        class="mt-1 w-full rounded-lg text-sm focus:ring-[#16C3D9]"
                        :class="form.errors.name ? 'border-red-400 focus:border-red-400' : 'border-gray-300 focus:border-[#16C3D9]'"
                    />
                    <span v-if="form.errors.name" class="mt-1 block text-xs font-semibold text-red-700">{{ form.errors.name }}</span>
                </label>

                <div>
                    <span class="text-xs font-bold text-gray-600">Image</span>
                    <img :src="previewSrc" @error="useDefaultImage" alt="" class="mt-1 h-36 w-full rounded-lg object-cover" />
                    <p v-if="!hasOwnImage" class="mt-1 text-xs text-gray-500">No image yet — this is the default one.</p>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <input
                            id="website_image" type="file" accept="image/png,image/jpeg,image/gif,image/webp" @change="handleImageChange"
                            aria-label="Choose an image"
                            class="min-w-0 flex-1 text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700"
                        />
                        <button
                            v-if="hasOwnImage" type="button" @click="removeImage"
                            class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        >Use the default</button>
                    </div>
                    <span v-if="fileError || form.errors.image" class="mt-1 block text-xs font-semibold text-red-700">{{ fileError || form.errors.image }}</span>
                    <span v-else class="mt-1 block text-xs text-gray-500">JPG, PNG, GIF or WebP, up to {{ maxImageMb }} MB. A wide picture fits best.</span>
                </div>
            </form>

            <template #footer>
                <button type="button" @click="closeForm" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" form="website-form" :disabled="form.processing" class="rounded-lg bg-[#16C3D9] px-4 py-2 text-sm font-bold text-[#06242A] hover:brightness-105 disabled:opacity-50">
                    {{ form.processing ? 'Saving…' : (isEditing ? 'Save' : 'Create') }}
                </button>
            </template>
        </Modal>
    </AuthenticatedLayout>
</template>
