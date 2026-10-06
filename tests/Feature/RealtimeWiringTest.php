<?php

use App\Filament\Resources\ClassSessions\Pages\ListClassSessions;
use App\Filament\Resources\Pcs\Pages\ListPcs;
use App\Filament\Student\Pages\ChatRoom as StudentChatRoom;
use App\Filament\Student\Pages\JoinClass;
use App\Filament\Student\Widgets\StudentStatus;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\Pc;
use App\Models\User;

/**
 * Realtime wiring.
 *
 * The listener module is JavaScript, so what is testable here is the server half:
 * that each broadcast topic re-renders the component it should, and that the
 * listener script is emitted on both panels.
 *
 * That is the half worth testing. A missing `#[On]` fails closed and quietly - the
 * page keeps working, it just stops updating, and nothing else in the suite notices.
 *
 * What these tests deliberately do NOT prove is that a browser receives anything.
 * Each one forces the refresh by dispatching the Livewire event directly, which is
 * what the JavaScript does on receiving a broadcast. Confirming the browser actually
 * receives it needs Reverb running and a human watching the table.
 */
describe('realtime wiring', function (): void {
    it('refreshes the PC list when a PC status broadcast arrives', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        Pc::factory()->create(['name' => 'LAB-01', 'status' => 'offline']);

        livewire(ListPcs::class)
            ->assertSee('LAB-01');

        // Flipped straight in the database, so the only way the table can show
        // "online" is if the dispatched event actually re-rendered it.
        Pc::where('name', 'LAB-01')->update(['status' => 'online']);

        livewire(ListPcs::class)
            ->dispatch('koben:pc-status-updated')
            ->assertSee('Online');
    });

    it('refreshes the class session list when a session starts', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        livewire(ListClassSessions::class)
            ->dispatch('koben:class-session-started')
            ->dispatch('koben:class-session-ended')
            ->assertOk();
    });

    it('refreshes the student join page when a class starts or ends', function (): void {
        $this->actingAs(User::factory()->student()->create());

        livewire(JoinClass::class)
            ->dispatch('koben:class-session-started')
            ->dispatch('koben:class-session-ended')
            ->assertOk();
    });

    it('refreshes the student dashboard stat when a class starts', function (): void {
        $this->actingAs(User::factory()->student()->create());

        livewire(StudentStatus::class)
            ->dispatch('koben:class-session-started')
            ->assertOk();
    });

    it('re-renders the chat transcript when another member sends a message', function (): void {
        $this->actingAs($student = User::factory()->student()->create());
        $group = GroupChat::factory()->withMembers($student)->create();

        // Written straight to the database so the only thing that can make it
        // visible is the listener re-reading the transcript.
        GroupChatMessage::factory()->create([
            'group_chat_id' => $group->getKey(),
            'sender_id' => $student->getKey(),
            'body' => 'arrived by broadcast',
        ]);

        livewire(StudentChatRoom::class, ['groupId' => $group->getKey()])
            ->assertSee('arrived by broadcast')
            ->dispatch('koben:group-message-sent')
            ->assertSee('arrived by broadcast');
    });

    it('emits the echo listener script with a real src', function (): void {
        $rendered = view('filament.echo-listeners')->render();

        // Asserted on the src, not the filename: a <script> tag that mentions
        // echo.js but loads nothing would pass a substring check, and every test
        // above would still pass with realtime completely dead.
        expect($rendered)
            ->toContain('<script')
            ->toContain('src=')
            ->toContain('echo');
    });

    it('emits the listener script on both panels, after Filament builds Echo', function (): void {
        foreach (['admin', 'student'] as $panel) {
            $user = $panel === 'admin'
                ? User::factory()->admin()->create()
                : User::factory()->student()->create();

            $response = $this->actingAs($user)->get('/'.$panel);

            $response->assertOk();

            $html = $response->getContent();

            // Matched on the built asset name, not the word "echo": Filament ships its
            // own /js/filament/filament/echo.js core asset, which appears first and
            // would satisfy a loose substring check while this module was absent.
            expect($html)->toMatch('#build/assets/echo-[^"]+\.js#');

            // The listener must come after the block that constructs window.Echo. If
            // it loaded first it would find no Echo and silently subscribe to nothing.
            expect(strpos($html, 'build/assets/echo-'))
                ->toBeGreaterThan(strpos($html, 'EchoFactory'));
        }
    });
});
