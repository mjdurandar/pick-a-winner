<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Edit Sign Up Form for ') . $event->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 col-md-5">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                
                <!-- Display the current banner image if it exists -->
                @if($form->banner_image)
                    <div class="mb-4">
                        <img src="{{ asset('storage/' . $form->banner_image) }}" alt="Banner Image" class="w-full h-auto">
                    </div>
                @endif

                <!-- Form to edit the sign-up form and upload a new banner image -->
                <form action="{{ route('form-builder.update', [$event->id, $form->id]) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <!-- Field to upload a new banner image -->
                    <div class="mb-4">
                        <label for="banner_image" class="block text-gray-700 text-sm font-bold mb-2">Upload New Banner Image</label>
                        <input type="file" id="banner_image" name="banner_image" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none">
                        <p class="text-xs text-gray-500 mt-1">Leave empty if you don't want to change the banner image.</p>
                    </div>

                    <!-- Existing form fields for editing -->
                    <div id="fields-container">
                        @foreach($form->fields as $index => $field)
                            <div class="field-group mb-4" id="field-group-{{ $index }}">
                                <label for="field_name_{{ $index }}" class="block text-gray-700 text-sm font-bold mb-2">Field Name</label>
                                <input type="text" id="field_name_{{ $index }}" name="fields[{{ $index }}][name]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" value="{{ old('fields.' . $index . '.name', $field->name) }}" required>

                                <label for="field_type_{{ $index }}" class="block text-gray-700 text-sm font-bold mb-2">Field Type</label>
                                <select id="field_type_{{ $index }}" name="fields[{{ $index }}][type]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none field-type-select" data-index="{{ $index }}">
                                    <option value="text" {{ $field->type === 'text' ? 'selected' : '' }}>Text</option>
                                    <option value="email" {{ $field->type === 'email' ? 'selected' : '' }}>Email</option>
                                    <option value="number" {{ $field->type === 'number' ? 'selected' : '' }}>Number</option>
                                    <option value="dropdown" {{ $field->type === 'dropdown' ? 'selected' : '' }}>Dropdown</option>
                                </select>

                                <div class="mt-2 field-options-container" id="field_options_container_{{ $index }}" style="{{ $field->type === 'dropdown' ? 'display: block;' : 'display: none;' }}">
                                    <label for="field_options_{{ $index }}" class="block text-gray-700 text-sm font-bold mb-2">Dropdown Options (comma-separated)</label>
                                    <input type="text" id="field_options_{{ $index }}" name="fields[{{ $index }}][options]" class="border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none" value="{{ old('fields.' . $index . '.options', $field->options) }}">
                                </div>

                                <button type="button" class="btn btn-danger mt-2 delete-field" data-index="{{ $index }}">Delete Field</button>
                            </div>
                        @endforeach
                    </div>

                    <button type="button" id="add-field" class="btn btn-secondary mb-4">Add Another Field</button>

                    <div class="flex items-center justify-center">
                        <button type="submit" class="btn btn-primary">
                            Update Sign Up Form
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let fieldCount = {{ count($form->fields) }};

        document.getElementById('add-field').addEventListener('click', function () {
            const fieldsContainer = document.getElementById('fields-container');

            const fieldGroup = document.createElement('div');
            fieldGroup.classList.add('field-group', 'mb-4');
            fieldGroup.setAttribute('id', `field-group-${fieldCount}`);
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

                <button type="button" class="btn btn-danger mt-2 delete-field" data-index="${fieldCount}">Delete Field</button>
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

            document.querySelector(`#field-group-${fieldCount} .delete-field`).addEventListener('click', function () {
                const index = this.getAttribute('data-index');
                const fieldGroup = document.getElementById(`field-group-${index}`);
                fieldGroup.remove();
            });

            fieldCount++;
        });

        document.querySelectorAll('.delete-field').forEach(function (button) {
            button.addEventListener('click', function () {
                const index = this.getAttribute('data-index');
                const fieldGroup = document.getElementById(`field-group-${index}`);
                fieldGroup.remove();
            });
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

<script>
    @if(session('success'))
        Swal.fire({
            title: 'Success!',
            text: "{{ session('success') }}",
            icon: 'success',
            confirmButtonText: 'OK'
        });
    @endif

    @if(session('error'))
        Swal.fire({
            title: 'Error!',
            text: "{{ session('error') }}",
            icon: 'error',
            confirmButtonText: 'OK'
        });
    @endif
</script>
