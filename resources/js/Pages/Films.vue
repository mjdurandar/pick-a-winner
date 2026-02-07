<script setup>
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Swal from 'sweetalert2';

const props = defineProps({
    films: { type: Array, default: () => [] }
});

const page = usePage();
const userRole = computed(() => page?.props?.auth?.user?.role ?? null);
const userEmail = computed(() => page?.props?.auth?.user?.email ?? '');
const canDelete = computed(() => userRole.value === 'admin' && userEmail.value === 'mj@adventureentertainment.com');

const isEditing = ref(false);
const form = useForm({
    id: null,
    name: ''
});

function openCreateModal() {
    isEditing.value = false;
    form.reset();
    const el = document.getElementById('createBrandModal');
    if (el) new bootstrap.Modal(el).show();
}

function openEditModal(brand) {
    isEditing.value = true;
    form.id = brand.id;
    form.name = brand.name;
    const el = document.getElementById('createBrandModal');
    if (el) new bootstrap.Modal(el).show();
}

function saveBrand() {
    if (isEditing.value) {
        form.patch(route('films.update', form.id), {
            onSuccess: () => {
                bootstrap.Modal.getInstance(document.getElementById('createBrandModal'))?.hide();
                Swal.fire('Updated!', 'Brand has been updated.', 'success');
                form.reset();
            },
            onError: () => Swal.fire('Error!', 'There was an issue updating the brand.', 'error')
        });
    } else {
        form.post(route('films.store'), {
            onSuccess: () => {
                bootstrap.Modal.getInstance(document.getElementById('createBrandModal'))?.hide();
                Swal.fire('Created!', 'Brand has been created.', 'success');
                form.reset();
            },
            onError: () => Swal.fire('Error!', 'There was an issue creating the brand.', 'error')
        });
    }
}

function deleteBrand(brandId) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('films.destroy', { film: brandId }), {
                onSuccess: () => {
                    router.visit(route('films.index'), { preserveState: false });
                    Swal.fire('Deleted!', 'Brand has been deleted.', 'success');
                },
                onError: (errors) => {
                    let msg = 'There was an issue deleting the brand.';
                    if (typeof errors === 'string') msg = errors;
                    else if (errors?.message) msg = errors.message;
                    Swal.fire('Error!', msg, 'error');
                }
            });
        }
    });
}
</script>

<template>
    <Head title="Brands" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800 leading-tight">Brands</h2>
                <button
                    v-if="userRole === 'admin'"
                    type="button"
                    @click="openCreateModal"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-md hover:bg-teal-700 text-sm font-medium"
                >
                    <i class="fa-solid fa-plus"></i> Create Brand
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div v-if="props.films.length === 0" class="text-center py-12 text-gray-500">
                            <p class="text-lg">No brands yet.</p>
                            <p class="mt-1">Create a brand to get started.</p>
                            <button
                                v-if="userRole === 'admin'"
                                type="button"
                                @click="openCreateModal"
                                class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-md hover:bg-teal-700"
                            >
                                <i class="fa-solid fa-plus"></i> Create Brand
                            </button>
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr
                                        v-for="brand in props.films"
                                        :key="brand.id"
                                        class="hover:bg-gray-50"
                                    >
                                        <td class="px-4 py-3">
                                            <span class="font-medium text-gray-900">{{ brand.name }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <button
                                                v-if="userRole === 'admin'"
                                                type="button"
                                                @click="openEditModal(brand)"
                                                class="text-teal-600 hover:text-teal-800 mr-3"
                                                title="Edit"
                                            >
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <button
                                                v-if="canDelete"
                                                type="button"
                                                @click="deleteBrand(brand.id)"
                                                class="text-red-600 hover:text-red-800"
                                                title="Delete"
                                            >
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Brand modal -->
        <div class="modal fade" id="createBrandModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ isEditing ? 'Edit Brand' : 'Create Brand' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form @submit.prevent="saveBrand">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Brand Name</label>
                                <input v-model="form.name" type="text" class="form-control" required maxlength="255" :class="{ 'is-invalid': form.errors.name }" />
                                <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                {{ isEditing ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
