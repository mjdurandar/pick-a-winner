<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Create Sign Up Form for ') . $event->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 col-md-5">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <form action="{{ route('form-builder.store', $event->id) }}" method="POST">
                    @csrf
                    <div id="fields-container">
                        <div class="field-group mb-4">
                            <label for="field_name_0" class="block text-gray-700 text-sm font-bold mb-2">Field Name</label>
                            <input type="text" id="field_name_0" name="fields[0][name]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" required>

                            <label for="field_type_0" class="block text-gray-700 text-sm font-bold mb-2">Field Type</label>
                            <select id="field_type_0" name="fields[0][type]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none field-type-select" data-index="0">
                                <option value="text">Text</option>
                                <option value="email">Email</option>
                                <option value="number">Number</option>
                                <option value="dropdown">Dropdown</option>
                            </select>

                            <div class="mt-2 field-options-container" id="field_options_container_0" style="display: none;">
                                <label for="field_options_0" class="block text-gray-700 text-sm font-bold mb-2">Dropdown Options (comma-separated)</label>
                                <input type="text" id="field_options_0" name="fields[0][options]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                            </div>
                        </div>
                    </div>

                    <button type="button" id="add-field" class="btn btn-secondary mb-4">Add Another Field</button>

                    <div class="flex items-center justify-center">
                        <button type="submit" class="btn btn-primary">
                            Save Sign Up Form
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let fieldCount = 1;

        document.getElementById('add-field').addEventListener('click', function () {
            const fieldsContainer = document.getElementById('fields-container');

            const fieldGroup = document.createElement('div');
            fieldGroup.classList.add('field-group', 'mb-4');
            fieldGroup.innerHTML = `
                <label for="field_name_${fieldCount}" class="block text-gray-700 text-sm font-bold mb-2">Field Name</label>
                <input type="text" id="field_name_${fieldCount}" name="fields[${fieldCount}][name]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" required>

                <label for="field_type_${fieldCount}" class="block text-gray-700 text-sm font-bold mb-2">Field Type</label>
                <select id="field_type_${fieldCount}" name="fields[${fieldCount}][type]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none field-type-select" data-index="${fieldCount}">
                    <option value="text">Text</option>
                    <option value="email">Email</option>
                    <option value="number">Number</option>
                    <option value="dropdown">Dropdown</option>
                </select>

                <div class="mt-2 field-options-container" id="field_options_container_${fieldCount}" style="display: none;">
                    <label for="field_options_${fieldCount}" class="block text-gray-700 text-sm font-bold mb-2">Dropdown Options (comma-separated)</label>
                    <input type="text" id="field_options_${fieldCount}" name="fields[${fieldCount}][options]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                </div>
            `;

            fieldsContainer.appendChild(fieldGroup);

            document.getElementById(`field_type_${fieldCount}`).addEventListener('change', function () {
                const index = this.getAttribute('data-index');
                const optionsContainer = document.getElementById(`field_options_container_${index}`);
                if (this.value === 'dropdown') {
                    optionsContainer.style.display = 'block';
                } else {
                    optionsContainer.style.display = 'none';
                }
            });

            fieldCount++;
        });

        document.querySelectorAll('.field-type-select').forEach(function (select) {
            select.addEventListener('change', function () {
                const index = this.getAttribute('data-index');
                const optionsContainer = document.getElementById(`field_options_container_${index}`);
                if (this.value === 'dropdown') {
                    optionsContainer.style.display = 'block';
                } else {
                    optionsContainer.style.display = 'none';
                }
            });
        });
    </script>
</x-app-layout>
