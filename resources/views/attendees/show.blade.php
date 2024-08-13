<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Responses for ') . $eventName->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    @if($formResponses->isEmpty())
                        <p>No responses found for this form.</p>
                    @else
                        <table class="min-w-full bg-white dark:bg-gray-800">
                            <thead>
                                <tr>
                                    @foreach($formFields as $field)
                                        <th class="py-2 px-4 border-b-2 border-gray-300 dark:border-gray-700 text-left leading-tight">
                                            {{ $field->name }}
                                        </th>
                                    @endforeach
                                    <th class="py-2 px-4 border-b-2 border-gray-300 dark:border-gray-700 text-left leading-tight">
                                        Submitted At
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($formResponses as $response)
                                    <tr>
                                        @php
                                            $responseData = json_decode($response->responses, true);
                                        @endphp
                                        @foreach($formFields as $field)
                                            <td class="py-2 px-4 border-b border-gray-300 dark:border-gray-700">
                                                {{ $responseData[$field->id] ?? 'N/A' }}
                                            </td>
                                        @endforeach
                                        <td class="py-2 px-4 border-b border-gray-300 dark:border-gray-700">
                                            {{ $response->created_at }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
