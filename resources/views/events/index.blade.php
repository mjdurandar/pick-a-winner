<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight d-flex justify-content-between">
            {{ __('Events') }}
            <!-- Button trigger modal -->
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createEventModal">Create an Event</button>
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="row">
                        @foreach($events as $event)
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    @if($event->image)
                                        <img src="{{ asset('storage/' . $event->image) }}" class="card-img-top" alt="{{ $event->name }}" style="height: 200px; object-fit: cover;">
                                    @endif
                                    <div class="card-body">
                                        <h5 class="card-title" style="font-size: 1.5rem;">{{ $event->name }}</h5>
                                        <p class="card-text"><strong>Date:</strong> {{ $event->date }}</p>
                                        <p class="card-text">{{ $event->description }}</p>
                                        <div>
                                            @if($event->forms()->exists())
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                <a href="{{ route('events.edit', $event->id) }}" class="btn btn-secondary mt-3"><i class="fa-solid fa-pen-to-square"></i></a>
                                                </div>
                                                <div>
                                                <a href="{{ route('form-builder.view', $event->forms->first()->id) }}" class="btn btn-success mt-3"><i class="fa-solid fa-magnifying-glass"></i></a>
                                                <a href="{{ route('form-builder.edit', [$event->id, $event->forms->first()->id]) }}" class="btn btn-primary mt-3">Edit Form</a>
                                                </div>
                                            </div>
                                            @else
                                                <a href="{{ route('form-builder.create', $event->id) }}" class="btn btn-primary mt-3">Create Sign Up Form</a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="createEventModal" tabindex="-1" aria-labelledby="createEventModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createEventModalLabel">Create Event</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createEventForm" action="{{ route('events.store') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="eventName" class="form-label">Event Name</label>
                            <input type="text" class="form-control" id="eventName" name="name" placeholder="Enter event name" required>
                        </div>
                        <div class="mb-3">
                            <label for="eventImage" class="form-label">Event Image</label>
                            <input type="file" class="form-control border border-secondary p-2" id="eventImage" name="image">
                        </div>
                        <div class="mb-3">
                            <label for="eventDate" class="form-label">Event Date</label>
                            <input type="date" class="form-control" id="eventDate" name="date" required>
                        </div>
                        <div class="mb-3">
                            <label for="eventDescription" class="form-label">Event Description</label>
                            <textarea class="form-control" id="eventDescription" name="description" rows="3" placeholder="Enter event description"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Event</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
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