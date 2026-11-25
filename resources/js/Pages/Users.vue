<script setup>
import Swal from 'sweetalert2';
import { ref, computed } from 'vue';
import { useForm, router, Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    users: Array,
});

const isEditing = ref(false);
const showEdit = ref(false);
const search = ref('');

const form = useForm({
    id: null,
    name: '',
    email: '',
    role: '',
});

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.users ?? [];

    return (props.users ?? []).filter((u) =>
        `${u.name ?? ''} ${u.email ?? ''} ${u.role ?? ''}`.toLowerCase().includes(q));
});

const admins = computed(() => (props.users ?? []).filter((u) => u.role === 'admin').length);

const initials = (name) => (name ?? '?')
    .split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

const openEditModal = (user) => {
    isEditing.value = true;
    form.clearErrors();
    form.id = user.id;
    form.name = user.name;
    form.email = user.email;
    form.role = user.role;
    showEdit.value = true;
};

const closeEdit = () => {
    showEdit.value = false;
    isEditing.value = false;
    form.reset();
};

// Submit the form (Update)
const saveUser = () => {
    const data = new FormData();
    data.append('name', form.name);
    data.append('email', form.email);
    data.append('role', form.role);

    if (!isEditing.value) return;

    data.append('_method', 'PATCH'); // ✅ Use PATCH for updating
    router.post(route('users.update', form.id), data, {
        onSuccess: () => {
            closeEdit();
            Swal.fire('Updated!', 'User has been updated.', 'success');
        },
        onError: (errors) => {
            Swal.fire('Error!', 'There was an issue updating the user.', 'error');
            console.log(errors);
        },
    });
};

// Delete User
const deleteUser = (user) => {
    Swal.fire({
        title: `Delete ${user.name}?`,
        text: 'This action cannot be undone! All data related to this user will be deleted. To confirm, type DELETE below.',
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'Type DELETE to confirm',
        showCancelButton: true,
        confirmButtonText: 'Delete User',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#c42626',
        inputValidator: (value) => {
            if (value !== 'DELETE') {
                return 'You must type DELETE to confirm!';
            }
        },
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('users.destroy', user.id), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'User has been deleted.', 'success');
                },
            });
        }
    });
};
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold text-gray-500">
                        {{ users?.length ?? 0 }} {{ (users?.length ?? 0) === 1 ? 'person' : 'people' }} ·
                        {{ admins }} admin{{ admins === 1 ? '' : 's' }}
                    </p>
                    <h2 class="truncate text-2xl font-extrabold tracking-tight text-gray-900">Users</h2>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-full px-4 py-6 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl bg-white shadow-card">
                <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 p-4">
                    <label class="flex h-10 min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 sm:max-w-sm">
                        <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <span class="sr-only">Search users</span>
                        <input
                            v-model="search" type="search" placeholder="Name, email or role"
                            class="w-full border-0 bg-transparent p-0 text-sm focus:ring-0"
                        />
                    </label>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-gray-50">
                                <th scope="col" class="whitespace-nowrap border-b border-gray-200 px-4 py-2.5 text-left text-[10.5px] font-bold uppercase tracking-wider text-gray-500">Name</th>
                                <th scope="col" class="whitespace-nowrap border-b border-gray-200 px-4 py-2.5 text-left text-[10.5px] font-bold uppercase tracking-wider text-gray-500">Email</th>
                                <th scope="col" class="whitespace-nowrap border-b border-gray-200 px-4 py-2.5 text-left text-[10.5px] font-bold uppercase tracking-wider text-gray-500">Role</th>
                                <th scope="col" class="border-b border-gray-200 px-4 py-2.5"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in visible" :key="user.id" class="border-b border-gray-100 last:border-b-0 hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-100 text-[11px] font-bold text-gray-600">{{ initials(user.name) }}</span>
                                        <span class="font-semibold text-gray-900">{{ user.name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ user.email }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-[11.5px] font-bold capitalize"
                                        :class="user.role === 'admin' ? 'bg-[#ECFAFD] text-[#0B7A8A]' : 'bg-gray-100 text-gray-600'"
                                    >{{ user.role }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button
                                        type="button" @click="openEditModal(user)"
                                        class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                    >Edit</button>
                                    <button
                                        type="button" @click="deleteUser(user)"
                                        class="ms-2 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:border-red-300 hover:bg-red-50"
                                    >Delete</button>
                                </td>
                            </tr>
                            <tr v-if="!visible.length">
                                <td colspan="4" class="px-4 py-12 text-center text-sm text-gray-400">
                                    {{ search ? 'Nobody matches that.' : 'No users yet.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Edit a user -->
        <Modal :show="showEdit" max-width="md" title="Edit user" @close="closeEdit">
            <!-- The buttons live in the footer, outside this form, and reach it
                 by id — so Enter still submits and required fields still check. -->
            <form id="user-form" @submit.prevent="saveUser" class="p-6">
                <p class="text-sm text-gray-500">Changing a role changes what this person can reach straight away.</p>

                <div class="mt-5 grid gap-4">
                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Name</span>
                        <input v-model="form.name" type="text" required class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Email</span>
                        <input v-model="form.email" type="email" required class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Role</span>
                        <select v-model="form.role" required class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]">
                            <option value="host">Host — films, locations and draws</option>
                            <option value="admin">Admin — everything</option>
                        </select>
                    </label>
                </div>
            </form>

            <template #footer>
                <button type="button" @click="closeEdit" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" form="user-form" :disabled="form.processing" class="rounded-lg bg-[#16C3D9] px-4 py-2 text-sm font-bold text-[#06242A] hover:brightness-105 disabled:opacity-50">Save</button>
            </template>
        </Modal>
    </AuthenticatedLayout>
</template>
