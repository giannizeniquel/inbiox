import { Injectable, signal, computed } from '@angular/core';

// TODO: Migrate to Ticket model or remove if unused
interface Chat {
  id: string;
  assignedAgent?: string;
  fsmStatus: string;
  unreadCount: number;
}

const MAX_CHATS = 1000;

export type InboxFilter = 'mine' | 'waiting' | 'internal';

/**
 * InboxStore — manages inbox chat list state via Angular signals.
 */
@Injectable({ providedIn: 'root' })
export class InboxStore {
  /** All loaded chats */
  readonly chats = signal<Chat[]>([]);

  /** Currently selected chat */
  readonly activeChat = signal<Chat | null>(null);

  /** Active filter tab */
  readonly filter = signal<InboxFilter>('mine');

  /** Loading state */
  readonly loading = signal<boolean>(false);

  /** Filtered chats based on current filter */
  readonly filteredChats = computed<Chat[]>(() => {
    const all = this.chats();
    const f = this.filter();

    switch (f) {
      case 'mine':
        return all.filter(c => c.assignedAgent !== undefined);
      case 'waiting':
        return all.filter(c => c.fsmStatus === 'waiting');
      case 'internal':
        return all.filter(c => c.fsmStatus === 'new' || c.fsmStatus === 'active');
      default:
        return all;
    }
  });

  /** Count for each filter tab */
  readonly filterCounts = computed(() => ({
    mine: this.chats().filter(c => c.assignedAgent !== undefined).length,
    waiting: this.chats().filter(c => c.fsmStatus === 'waiting').length,
    internal: this.chats().filter(c => c.fsmStatus === 'new' || c.fsmStatus === 'active').length,
  }));

  /** Total unread count across all chats */
  readonly totalUnread = computed(() =>
    this.chats().reduce((sum, c) => sum + c.unreadCount, 0)
  );

  /** Set loading state */
  setLoading(loading: boolean): void {
    this.loading.set(loading);
  }

  /** Set the active filter */
  setFilter(filter: InboxFilter): void {
    this.filter.set(filter);
  }

  /** Load chats, enforcing a max cap of 1000 */
  loadChats(chats: Chat[]): void {
    const capped = chats.slice(0, MAX_CHATS);
    this.chats.set(capped);
  }

  /** Set the active chat by ID */
  setActiveChat(chatId: string | null): void {
    if (!chatId) {
      this.activeChat.set(null);
      return;
    }
    const chat = this.chats().find(c => c.id === chatId) ?? null;
    this.activeChat.set(chat);
  }

  /** Update unread count for a specific chat */
  updateUnreadCount(chatId: string, count: number): void {
    this.chats.update(chats =>
      chats.map(c => c.id === chatId ? { ...c, unreadCount: count } : c)
    );
  }

  /** Mark a chat as read */
  markAsRead(chatId: string): void {
    this.chats.update(chats =>
      chats.map(c => c.id === chatId ? { ...c, unreadCount: 0 } : c)
    );
  }

  /** Reset store state */
  reset(): void {
    this.chats.set([]);
    this.activeChat.set(null);
    this.filter.set('mine');
    this.loading.set(false);
  }
}
