<div class="p-6 max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">SearchChat</h1>

    <div class="space-y-4 mb-6">
        @foreach($messages as $msg)
        <div class="{{ $msg['role'] === 'user' ? 'text-right' : 'text-left' }}">
            <div class="inline-block p-4 rounded-lg {{ $msg['role'] === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}">
                {{ $msg['content'] }}
            </div>
        </div>
        @endforeach
    </div>

    <div class="flex gap-2">
        <input wire:model="question"
            wire:keydown.enter="ask"
            class="border p-2 flex-1 rounded"
            placeholder="Escribe tu consulta...">
        <button wire:click="ask"
            class="bg-indigo-600 text-white px-4 py-2 rounded">
            Enviar
        </button>
    </div>
</div>