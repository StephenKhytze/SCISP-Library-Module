import React, { useState, useMemo, useEffect, useCallback, useRef } from 'react';
import api from '../../api';
import TeacherReservesView from './TeacherReservesView';
import AdminFinesPanel from './AdminFinesPanel';
import AdminInventoryPanel from './AdminInventoryPanel';
import HoldQueuePanel from './HoldQueuePanel';
import RenewalRequestsPanel from './RenewalRequestsPanel';
import LibrarySettingsPanel from './LibrarySettingsPanel';
import StudentCourseReserveCard from './StudentCourseReserveCard';
import AdminReserveCard from './AdminReserveCard';
import ClassmatesModal from './ClassmatesModal';
import StatusBadge from './StatusBadge';
import AddBookForm from './AddBookForm';
import CategorySelect from './CategorySelect';
import { useToast } from './ToastProvider';
import { useConfirm } from './ConfirmDialog';
import useDialog from './useDialog';
import { Loader2, Package, Settings, BookOpen } from 'lucide-react';

/**
 * A book cover that degrades to the placeholder icon rather than the browser's
 * broken-image glyph. Every cover in this file renders through here, so a
 * missing file, a dead link or an offline backend all look the same: a plain
 * placeholder, never a broken picture with the title spilled across it.
 *
 * The skeleton is driven by state instead of removing a DOM node React owns.
 */
function BookCover({ src, alt = '', iconClassName = 'w-6 h-6 text-slate-300' }) {
  const [failed, setFailed] = useState(false);
  const [loaded, setLoaded] = useState(false);

  // Retry when the cover is replaced: a new src is a new image.
  useEffect(() => {
    setFailed(false);
    setLoaded(false);
  }, [src]);

  if (!src || failed) return <BookOpen className={iconClassName} />;

  return (
    <>
      {!loaded && <div className="absolute inset-0 bg-slate-200 animate-pulse" />}
      <img
        src={src}
        alt={alt}
        className="w-full h-full object-cover relative z-10"
        onLoad={() => setLoaded(true)}
        onError={() => setFailed(true)}
      />
    </>
  );
}

export default function LibraryPortal() {
  const toast = useToast();
  const confirm = useConfirm();
    const [activeTab, setActiveTab] = useState('catalog');
  const [searchQuery, setSearchQuery] = useState('');
  // '' means every category. It is also the dropdown's "All Categories" value.
  const [selectedCategory, setSelectedCategory] = useState('');

  const [books, setBooks] = useState([]);
  const [circulation, setCirculation] = useState([]);
  const [reserves, setReserves] = useState([]);
  const [holdRequests, setHoldRequests] = useState([]);
  const [finesData, setFinesData] = useState({ debtors: [], total_outstanding: 0, daily_rate: 10 });
  const [myLoans, setMyLoans] = useState([]);
  const [myHistory, setMyHistory] = useState([]);
  const [summary, setSummary] = useState(null);

  // `loading` covers the first paint only. Later fetches set `refreshing`, so
  // the page updates in place instead of collapsing back into skeletons after
  // every action.
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const hasLoadedRef = useRef(false);
  const [loadError, setLoadError] = useState('');

  // Keyed by action so only the button that was pressed shows a busy state.
  const [actionBusy, setActionBusy] = useState(null);

  // Faculty do not receive a reserve list of their own; TeacherReservesView
  // reports what it loaded so the tab badge can show a real number.
  const [facultyReserveCount, setFacultyReserveCount] = useState(null);

  // The course reserve whose classmates panel is open, and its roster once
  // fetched. `undefined` classmates means "not loaded", which is what the modal
  // renders as its honest unavailable state.
  // Which management screen the Add Title & Copies tab is showing.
  const [manageSection, setManageSection] = useState('inventory');

  const [classmatesFor, setClassmatesFor] = useState(null);
  const [classmates, setClassmates] = useState(undefined);

  // Renewal requests awaiting a librarian decision.
  const [pendingRenewals, setPendingRenewals] = useState([]);

  // Separate from `loading` so opening a desk tab does not blank the page.
  const [panelLoading, setPanelLoading] = useState(false);

  // Server-side catalog search + pagination, so results are not capped at one page.
  const [categories, setCategories] = useState([]);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [debouncedSearch, setDebouncedSearch] = useState('');

  // Admin circulation list: active only, or full history.
  const [circulationScope, setCirculationScope] = useState('active');

  // Modals
  const [selectedBook, setSelectedBook] = useState(null);
  const [showAddBookModal, setShowAddBookModal] = useState(false);

  // New Book Form State
  const [newBook, setNewBook] = useState({
    title: '',
    author: '',
    isbn: '',
    edition: '',
    publisher: '',
    publication_year: '',
    // No default: the old hardcoded value matched no category any book used,
    // so an untouched field silently filed the title under a phantom name.
    category: '',
    copies: 2,
    location: 'Floor 2 - Shelf CS-101',
    isCourseReserve: false
  });

  const [checkoutData, setCheckoutData] = useState({ userId: '', bookId: '', copyId: '' });
  const [circulationSearch, setCirculationSearch] = useState('');
  const [myHolds, setMyHolds] = useState([]);
  const [selectedLoan, setSelectedLoan] = useState(null);

  const bookModalRef = useDialog(!!selectedBook, () => setSelectedBook(null));
  const loanModalRef = useDialog(!!selectedLoan, () => setSelectedLoan(null));
  const addBookModalRef = useDialog(showAddBookModal, () => setShowAddBookModal(false));

  const userStr = localStorage.getItem('user');
  const user = userStr ? JSON.parse(userStr) : null;

  // Both administrator personas run the desk, so `isLibrarian` still gates every
  // management screen. What changed is BORROWING: a Super Admin is
  // management-only and has no borrower side at all.
  // Absence of a stored user is NOT treated as admin.
  const normalizedRole = String(user?.role || '').toLowerCase();
  const isLibrarian = normalizedRole.includes('admin');
  const isSuperAdminPersona = normalizedRole.includes('super');
  const isFaculty = normalizedRole.includes('teacher') || normalizedRole.includes('faculty');
  const isStudent = !isLibrarian && !isFaculty;

  const peso = (n) => `₱${Number(n || 0).toFixed(2)}`;

  const formatBook = (b) => ({
    id: b.book_id,
    title: b.book_title,
    author: b.author,
    isbn: b.isbn,
    location: b.physical_location,
    categories: b.category ? [b.category] : [],
    available: b.available_copies_count ?? 0,
    total: b.total_copies ?? 0,
    copies: b.copies || [],
    edition: b.edition,
    publisher: b.publisher,
    publicationYear: b.publication_year,
    reservedCount: b.reserved_copies_count ?? 0,
    isArchived: b.is_archived ?? false,
    // A real cover when one has been uploaded; the stock placeholder otherwise.
    image: b.cover_url || "https://images.unsplash.com/photo-1555066931-4365d14bab8c?auto=format&fit=crop&q=80&w=300&h=300"
  });

  // Categories change only when a title is added, so they are fetched once and
  // reused rather than re-requested on every load.
  const categoriesLoadedRef = useRef(false);

  /**
   * Everything the current role actually needs on first paint.
   *
   * Borrower endpoints are skipped entirely for a Super Admin — they cannot
   * borrow, so those four calls could only ever return empty. Librarian
   * datasets are NOT loaded here; they come in when their tab is opened.
   */
  const fetchCore = useCallback(async () => {
    try {
      if (hasLoadedRef.current) setRefreshing(true); else setLoading(true);
      setLoadError('');

      const bookParams = { page, per_page: 12 };
      if (debouncedSearch) bookParams.search = debouncedSearch;
      if (selectedCategory) bookParams.category = selectedCategory;

      // The summary tells us whether this account has a borrower side at all.
      const borrowerLikely = !isSuperAdminPersona;

      const requests = [
        api.get('/library/books', { params: bookParams }),
        api.get('/library/me/summary'),
      ];

      // Captured now, not re-read after the await: two effects can be in
      // flight at once (React StrictMode does exactly this in development), and
      // if the other one finishes first the flag flips mid-parse and every
      // response below is read from the wrong index.
      const requestedCategories = !categoriesLoadedRef.current;
      if (requestedCategories) requests.push(api.get('/library/categories'));

      if (borrowerLikely) {
        requests.push(
          api.get('/library/loans/me'),
          api.get('/library/holds/me'),
          api.get('/library/loans/me/history'),
        );
      }

      if (borrowerLikely && isStudent) {
        requests.push(api.get('/library/sections/me'));
      }

      const results = await Promise.all(requests);

      let cursor = 0;
      const booksRes = results[cursor++];
      const summaryRes = results[cursor++];
      const categoriesRes = requestedCategories ? results[cursor++] : null;

      const payload = booksRes.data || {};
      const booksArray = Array.isArray(payload) ? payload : (payload.data || []);

      setBooks(booksArray.map(formatBook));
      setPagination({
        current_page: payload.current_page ?? 1,
        last_page: payload.last_page ?? 1,
        total: payload.total ?? booksArray.length,
      });

      if (categoriesRes) {
        setCategories(categoriesRes.data || []);
        categoriesLoadedRef.current = true;
      }

      setSummary(summaryRes.data || null);

      if (borrowerLikely) {
        const myLoansRes = results[cursor++];
        const myHoldsRes = results[cursor++];
        const historyRes = results[cursor++];

        setMyLoans(myLoansRes.data || []);
        setMyHistory(historyRes.data || []);
        setMyHolds((myHoldsRes.data || []).map(h => ({
          id: h.hold_id,
          queuePosition: h.queue_position,
          pos: h.status === 'pending_approval' ? 'Waiting for Approval' : (h.queue_position > 0 ? `#${h.queue_position}` : 'Ready for Pickup'),
          bookTitle: h.book?.book_title,
          date: h.created_at,
          status: h.status
        })));
      } else {
        setMyLoans([]);
        setMyHistory([]);
        setMyHolds([]);
      }

      if (borrowerLikely && isStudent) {
        const studentSectionsRes = results[cursor++];

        // The reserve card's own state now comes from the server, which is the
        // only place that can see this student's requests and queue position.
        const myReserves = [];

        (studentSectionsRes.data || []).forEach(section => {
          section.reserves?.forEach(r => {
            myReserves.push({
              id: r.reserve_id,
              section_id: section.section_id,
              book_id: r.book_id ?? r.book?.book_id,
              course: section.name,
              status: r.status,
              title: r.book?.book_title || 'N/A',
              type: r.target_group || 'Course Reserve',
              requester: section.teacher?.username || 'Teacher',
              role: 'Teacher',
              date: r.created_at ? new Date(r.created_at).toLocaleDateString() : 'N/A',
              note: r.teacher_to_student_note || '',
              allocated_copies: r.allocated_copies ?? (r.copies || []).length,
              available_for_section: r.available_for_section ?? 0,
              student_request_status: r.student_request_status,
              queue_position: r.queue_position,
            });
          });
        });

        setReserves(myReserves);
      }
    } catch (err) {
      console.error("Failed to fetch data", err);
      setLoadError(
        err.response?.data?.message ||
        'Could not load the library. Check your connection and try again.'
      );
    } finally {
      hasLoadedRef.current = true;
      setLoading(false);
      setRefreshing(false);
    }
  }, [isSuperAdminPersona, isStudent, page, debouncedSearch, selectedCategory]);

  /**
   * Librarian datasets, loaded only for the desk screen actually being opened.
   *
   * Previously all four arrived on first paint even when the librarian was
   * looking at the catalog. Tab badges no longer need them — those counts come
   * from the summary endpoint.
   */
  const fetchLibrarianTab = useCallback(async (tab) => {
    if (!isLibrarian) return;

    setPanelLoading(true);

    try {
      if (tab === 'circulation') {
        const res = await api.get('/library/circulation', { params: { status: circulationScope } });

        setCirculation((res.data || []).map(t => ({
          id: t.transaction_id,
          dueDate: t.due_date,
          accession: t.book_copy?.accession_number,
          title: t.book_copy?.book?.book_title,
          copyId: t.book_copy?.copy_id,
          author: t.book_copy?.book?.author,
          borrower: t.user?.username,
          borrowerId: t.user?.user_id,
          overdueDate: t.due_date,
          status: t.status,
          isOverdue: t.is_overdue,
          daysOverdue: t.days_overdue,
          estimatedFine: t.estimated_fine,
          returnedAt: t.actual_return_date
        })));
      }

      if (tab === 'reserves') {
        const res = await api.get('/library/reserves');

        setReserves((res.data || []).map(r => {
          const copies = r.copies || [];
          return {
            id: r.reserve_id,
            course: r.section?.name || 'N/A',
            status: r.status,
            title: r.book?.book_title || 'N/A',
            type: r.target_group || 'Course Reserve',
            requester: r.user?.username || 'Unknown',
            role: r.user?.role || 'Teacher',
            date: r.created_at ? new Date(r.created_at).toLocaleDateString() : 'N/A',
            note: r.teacher_to_admin_note || r.teacher_to_student_note || '',
            copies_requested: r.copies_requested ?? 0,
            allocated_copies: copies.length,
            available_for_section: copies.filter(c => c.availability_status === 'available').length,
          };
        }));
      }

      if (tab === 'fines') {
        const [holdsRes, finesRes, renewalsRes] = await Promise.all([
          api.get('/library/holds'),
          api.get('/library/fines'),
          api.get('/library/renewals'),
        ]);

        setHoldRequests((holdsRes.data || []).map(h => ({
          id: h.hold_id,
          queuePosition: h.queue_position,
          pos: h.status === 'pending_approval' ? 'Getting Approval' : `#${h.queue_position}`,
          status: h.status,
          bookTitle: h.book?.book_title,
          requester: h.user?.username,
          requesterId: h.user_id,
          copyId: h.copy_id,
          role: h.user?.role,
          date: h.created_at
        })));

        setFinesData(finesRes.data || { debtors: [], total_outstanding: 0, daily_rate: 10 });
        setPendingRenewals(renewalsRes.data || []);
      }
    } catch (err) {
      console.error('Failed to load librarian data', err);
      toast.error(err.response?.data?.message || 'Could not load this desk screen.');
    } finally {
      setPanelLoading(false);
    }
  }, [isLibrarian, circulationScope, toast]);

  /** Reload whatever the current screen is showing, after a mutation. */
  const refreshCurrent = useCallback(async () => {
    await fetchCore();
    if (isLibrarian && ['circulation', 'reserves', 'fines'].includes(activeTab)) {
      await fetchLibrarianTab(activeTab);
    }
  }, [fetchCore, fetchLibrarianTab, isLibrarian, activeTab]);

  // Kept as the name the rest of the component (and TeacherReservesView) calls.
  const fetchData = refreshCurrent;

  useEffect(() => {
    fetchCore();
  }, [fetchCore]);

  // Librarian screens load when they are opened, not on first paint.
  useEffect(() => {
    if (isLibrarian && ['circulation', 'reserves', 'fines'].includes(activeTab)) {
      fetchLibrarianTab(activeTab);
    }
  }, [isLibrarian, activeTab, fetchLibrarianTab]);

  // Debounce the catalog search so each keystroke does not hit the API.
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery.trim());
      setPage(1);
    }, 350);

    return () => clearTimeout(timer);
  }, [searchQuery]);

  /** Fold a newly created category into the list already in memory. */
  const registerCategory = useCallback((name) => {
    setCategories((prev) => (
      prev.includes(name) ? prev : [...prev, name].sort((a, b) => a.localeCompare(b))
    ));
  }, []);

  // Changing the category filter restarts paging.
  useEffect(() => { setPage(1); }, [selectedCategory]);

  // Search and category filtering are performed by the API, so results cover the
  // whole catalog rather than only the books already loaded on this page.
  const filteredBooks = books;

  const [holdSearchQuery, setHoldSearchQuery] = useState('');
  const [holdBookFilter, setHoldBookFilter] = useState('');
  const [holdStatusFilter, setHoldStatusFilter] = useState('');

  const filteredHoldRequests = useMemo(() => {
    return holdRequests.filter(req => {
      const q = holdSearchQuery.toLowerCase();
      const matchQuery = !holdSearchQuery ||
        req.bookTitle?.toLowerCase().includes(q) ||
        req.requester?.toLowerCase().includes(q) ||
        req.pos?.toString().toLowerCase().includes(q) ||
        req.requesterId?.toString().toLowerCase().includes(q);

      const matchBook = !holdBookFilter || req.bookTitle === holdBookFilter;
      const matchStatus = !holdStatusFilter || req.status === holdStatusFilter;

      return matchQuery && matchBook && matchStatus;
    });
  }, [holdRequests, holdSearchQuery, holdBookFilter, holdStatusFilter]);

  // Guards every mutating action: one at a time, and the pressed button says
  // so while the request is in flight.
  const runAction = async (key, fn) => {
    if (actionBusy) return;
    setActionBusy(key);
    try {
      await fn();
    } finally {
      setActionBusy(null);
    }
  };

  const handleCancelHold = (holdId) => runAction(`cancel-hold-${holdId}`, async () => {
    try {
      await api.delete(`/library/holds/${holdId}`);
      toast.success("Hold request cancelled.");
      fetchData();
    } catch (err) {
      console.error(err);
      toast.error(`Failed to cancel hold: ${err.response?.data?.message || err.message}`);
    }
  });

  const handleAcceptHold = (holdId) => runAction(`accept-hold-${holdId}`, async () => {
    try {
      await api.put(`/library/holds/${holdId}/accept`);
      toast.success("Hold request accepted! The book is now Ready for Pickup.");
      fetchData();
    } catch (err) {
      console.error(err);
      toast.error(`Failed to accept hold: ${err.response?.data?.message || err.message}`);
    }
  });

  const handleHoldRequest = (bookId) => runAction(`hold-${bookId}`, async () => {
    try {
      await api.post(`/library/books/${bookId}/holds`);
      toast.success(`Borrow/Hold request submitted successfully!`);
      setSelectedBook(null);
      fetchData();
    } catch (err) {
      console.error(err);
      toast.error(`Failed to submit request: ${err.response?.data?.error || err.response?.data?.message || err.message}`);
    }
  });

  const handleCheckout = async (e, userId, copyId) => {
    e.preventDefault();
    if (!userId || !copyId) {
      toast.warning("Please provide both Borrower ID and Copy ID.");
      return;
    }
    return runAction('checkout', async () => {
    try {
      await api.post('/library/checkout', { user_id: userId, copy_id: copyId });
      toast.success("Book checked out successfully!");
      fetchData();
    } catch (err) {
      console.error(err);
      toast.error(`Checkout failed: ${err.response?.data?.message || err.message}`);
    }
    });
  };

  const handleCheckin = async (e, transactionId) => {
    e.preventDefault();
    if (!transactionId) {
      toast.warning("Please provide the Transaction ID.");
      return;
    }
    return runAction(`checkin-${transactionId}`, async () => {
    try {
      await api.post('/library/checkin', { transaction_id: transactionId });
      toast.success("Book checked in successfully!");
      fetchData();
    } catch (err) {
      console.error(err);
      toast.error(`Check-in failed: ${err.response?.data?.message || err.message}`);
    }
    });
  };

  // Borrowers no longer extend their own due date; they ask, and a librarian
  // decides. The confirm wording says so.
  const handleRequestRenewal = async (transactionId) => {
    const ok = await confirm({
      title: 'Request Renewal',
      message: 'Ask a librarian to extend this loan? The due date stays as it is until they approve.',
      confirmText: 'Request',
    });
    if (!ok) return;

    return runAction(`renew-${transactionId}`, async () => {
      try {
        await api.post('/library/renewals', { transaction_id: transactionId });
        toast.success('Renewal requested. A librarian will review it.');
        setSelectedLoan(null);
        refreshCurrent();
      } catch (err) {
        console.error(err);
        toast.error(`Renewal request failed: ${err.response?.data?.error || err.response?.data?.message || err.message}`);
      }
    });
  };

  const handleDecideRenewal = (renewalId, approve) => runAction(`renewal-${renewalId}`, async () => {
    try {
      const res = approve
        ? await api.put(`/library/renewals/${renewalId}/approve`)
        : await api.put(`/library/renewals/${renewalId}/deny`);

      toast.success(res.data?.message || (approve ? 'Renewal approved.' : 'Renewal denied.'));
      refreshCurrent();
    } catch (err) {
      console.error(err);
      toast.error(err.response?.data?.error || err.response?.data?.message || 'Could not update this renewal.');
    }
  });

  // D-3: only this reserve's allocated copies are eligible, which the server
  // enforces; the card just reports what came back.
  const handleRequestReserveCopy = (reserve) => runAction(`reserve-request-${reserve.id}`, async () => {
    try {
      const res = await api.post(`/library/reserves/${reserve.id}/request`);
      toast.success(res.data?.message || 'Request submitted.');
      refreshCurrent();
    } catch (err) {
      console.error(err);
      toast.error(err.response?.data?.error || err.response?.data?.message || 'Could not request this course reserve.');
    }
  });

  const handleUpdateReserveStatus = (reserveId, status) => runAction(`reserve-${reserveId}-${status}`, async () => {
    try {
      await api.put(`/library/reserves/${reserveId}/status`, { status });
      toast.success(`Reserve ${status} successfully!`);
      fetchData();
    } catch (err) {
      console.error(err);
      toast.error(`Failed to update reserve: ${err.response?.data?.message || err.message}`);
    }
  });

  const handleAllocateCopies = (reserveId) => runAction(`reserve-${reserveId}-allocate`, async () => {
    try {
      await api.post(`/library/reserves/${reserveId}/allocate`);
      toast.success(`Physical copies allocated to reserve successfully!`);
      fetchData();
    } catch (err) {
      console.error(err);
      toast.error(`Failed to allocate copies: ${err.response?.data?.message || err.message}`);
    }
  });

  const handleAddBook = async (e) => {
    e.preventDefault();
    if (!newBook.title || !newBook.author || !newBook.isbn) {
      toast.warning('Please fill in required fields (Title, Author, ISBN).');
      return;
    }

    return runAction('add-book', async () => {
    try {
      const payload = {
        book_title: newBook.title,
        author: newBook.author,
        isbn: newBook.isbn,
        edition: newBook.edition,
        publisher: newBook.publisher,
        publication_year: newBook.publication_year ? parseInt(newBook.publication_year, 10) : null,
        physical_location: newBook.location,
        category: newBook.category,
        copies: parseInt(newBook.copies, 10) || 1
      };

      const res = await api.post('/library/books', payload);

      const b = res.data.book || res.data;
      const formatted = {
        id: b.book_id,
        title: b.book_title,
        author: b.author,
        isbn: b.isbn,
        edition: b.edition,
        publisher: b.publisher,
        publicationYear: b.publication_year,
        location: b.physical_location,
        categories: b.category ? [b.category] : [],
        available: b.available_copies_count ?? parseInt(newBook.copies, 10),
        total: b.total_copies ?? parseInt(newBook.copies, 10),
        image: null
      };
      setBooks([formatted, ...books]);

      setNewBook({
        title: '',
        author: '',
        isbn: '',
        edition: '',
        publisher: '',
        publication_year: '',
        category: '',
        copies: 2,
        location: 'Floor 2 - Shelf CS-101',
        isCourseReserve: false
      });
      setShowAddBookModal(false);
      toast.success(`Book "${formatted.title}" successfully added to the catalog!`);
      setActiveTab('catalog');
    } catch (err) {
      console.error(err);

      if (err.response?.status === 409 && err.response?.data?.existing_book) {
        const existing = err.response.data.existing_book;

        const addCopy = await confirm({
          title: 'This book already exists in the catalog.',
          message: `Are you sure you want to add another physical copy instead of creating a duplicate record?`,
          content: (
            <div className="flex gap-3 mt-4 bg-slate-50 p-3 rounded-xl border border-slate-200">
              <div className="relative w-12 h-16 shrink-0 bg-slate-100 rounded-md border border-slate-200 flex items-center justify-center overflow-hidden">
                <BookCover src={existing.cover_url} iconClassName="w-5 h-5 text-slate-300" />
              </div>
              <div className="min-w-0 flex-1">
                <h4 className="text-[12px] font-black text-[#0f172a] truncate">{existing.book_title}</h4>
                <p className="text-[10px] text-slate-500 truncate">by {existing.author}</p>
                <p className="text-[10px] text-slate-400 mt-1 truncate">
                  {existing.isbn ? `ISBN: ${existing.isbn}` : ''}
                  {existing.edition ? ` \u00b7 ${existing.edition}` : ''}
                </p>
                <p className="text-[10px] font-bold text-emerald-700 mt-1 bg-emerald-50 inline-block px-1.5 py-0.5 rounded border border-emerald-100">
                  {existing.total_copies || 0} existing {(existing.total_copies === 1) ? 'copy' : 'copies'}
                </p>
              </div>
            </div>
          ),
          confirmText: 'Add Copy Instead',
        });

        if (addCopy) {
          try {
            await api.post(`/library/books/${existing.book_id}/copies`, {
              quantity: parseInt(newBook.copies, 10) || 1,
            });
            toast.success(`Copies added to "${existing.book_title}".`);
            setShowAddBookModal(false);
            setActiveTab('catalog');
            refreshCurrent();
          } catch (copyErr) {
            toast.error(copyErr.response?.data?.error || 'Could not add copies to the existing title.');
          }
        }

        return;
      }

      toast.error(err.response?.data?.error || err.response?.data?.message || 'Failed to add book');
    }
    });
  };


  const filteredCirculation = useMemo(() => {
    return circulation.filter(item => {
      const q = circulationSearch.toLowerCase();
      if (!q) return true;
      return (
        item.copyId?.toString().includes(q) ||
        item.borrowerId?.toString().includes(q) ||
        item.borrower?.toLowerCase().includes(q) ||
        item.title?.toLowerCase().includes(q)
      );
    });
  }, [circulation, circulationSearch]);

  // Borrower-facing counters, sourced from the API rather than hardcoded.
  const activeLoanCount = summary?.active_loans ?? myLoans.length;
  const borrowLimit = summary?.borrow_limit ?? null;
  const myBalance = summary?.total_fines ?? 0;
  const overdueCount = summary?.overdue_loans ?? 0;
  const dailyRate = finesData?.daily_rate ?? 10;

  // Navigation badge counts. Every one of these is derived from loaded data;
  // none is a placeholder.
  //
  // The catalog badge is the whole catalog, not the current page.
  const catalogCount = pagination.total ?? books.length;

  // Active loans at the desk. When the scope filter is showing history the
  // returned rows must not inflate the badge.
  // Desk counts come from the summary endpoint so the badges are correct even
  // before those panels have been opened.
  const activeCirculationCount = summary?.librarian
    ? summary.librarian.active_loans
    : circulation.filter(item => item.status !== 'returned').length;

  // What a librarian still has to act on: holds awaiting a decision or pickup,
  // plus borrowers carrying a balance.
  const finesQueueCount = summary?.librarian
    ? summary.librarian.pending_holds + summary.librarian.debtors + summary.librarian.pending_renewals
    : holdRequests.length + (finesData?.debtors?.length ?? 0);

  // Faculty get their count from TeacherReservesView, which owns that data and
  // only loads once the tab is opened. Until then the count is unknown, and an
  // unknown count is shown as no badge rather than a misleading zero.
  const reserveBadgeCount = isFaculty
    ? facultyReserveCount
    : (summary?.librarian ? summary.librarian.total_reserves : reserves.length);

  // A Super Admin has no borrower side, so the borrowing tab is hidden outright.
  const canBorrow = summary ? summary.can_borrow !== false : !isSuperAdminPersona;

  // Copies that are genuinely on loan. A copy held for pickup is not checked
  // out, and neither is one merely allocated to a course reserve.
  const checkedOutCount = useMemo(() => {
    const fromCopies = books.reduce((n, b) => {
      const copies = b.copies || [];
      if (copies.length === 0) return n;
      return n + copies.filter(c => c.availability_status === 'checked_out').length;
    }, 0);
    return fromCopies;
  }, [books]);

  const onHoldCount = useMemo(
    () => books.reduce((n, b) => n + (b.copies || []).filter(c => c.availability_status === 'on_hold').length, 0),
    [books]
  );

  // The role card must not claim "STUDENT / no limit" for an admin while the
  // summary is still in flight.
  const roleLabel = summary
    ? (summary.is_super_admin
        ? 'SUPER ADMIN'
        : summary.role === 'faculty' ? 'FACULTY' : summary.role === 'administrator' ? 'LIBRARIAN' : 'STUDENT')
    : null;
  const loanDays = summary
    ? (summary.role === 'faculty' ? '14' : summary.role === 'administrator' ? '30' : '7')
    : null;

  const handleViewClassmates = async (reserve) => {
    setClassmatesFor(reserve);
    setClassmates(undefined);

    if (!reserve.section_id) return;

    try {
      const res = await api.get(`/library/sections/${reserve.section_id}/classmates`);
      setClassmates(res.data?.classmates ?? []);
    } catch (err) {
      // Leave the roster undefined so the modal keeps its honest
      // "unavailable" state rather than claiming the section is empty.
      console.error('Failed to load classmates', err);
    }
  };

  const handleFacultySections = useCallback((sections) => {
    setFacultyReserveCount(
      (sections || []).reduce((n, section) => n + (section.reserves?.length || 0), 0)
    );
  }, []);

  return (
    <div className="w-full font-sans text-slate-800 pb-20">

      {/* A failed load must be visible, not silently shown as an empty library. */}
      {loadError && (
        <div className="mx-4 lg:mx-0 mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
          <p className="text-[12px] font-bold text-rose-800">{loadError}</p>
          <button
            onClick={fetchData}
            className="bg-rose-600 hover:bg-rose-700 text-white text-[11px] font-extrabold py-1.5 px-4 rounded-lg cursor-pointer"
          >
            Retry
          </button>
        </div>
      )}

      {/* ========================================================================= */}
      {/* 📱 MOBILE VIEW (< lg): Retains Exact Original Mobile Layout               */}
      {/* ========================================================================= */}
      <div className="flex flex-col gap-6 w-full max-w-md sm:max-w-xl md:max-w-3xl mx-auto p-4 md:p-6 lg:hidden">

        {/* 1. Main Info Card */}
        <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100">
          <div className="flex flex-wrap gap-2 mb-4">
            <div className="inline-block bg-[#8B1A24] text-white text-[10px] font-black tracking-widest uppercase px-3 py-1 rounded-md">
              Library & Circulation System
            </div>
          </div>

          <h1 className="text-[28px] leading-[1.1] font-extrabold text-[#0f172a] mb-3 tracking-tight">
            Library Catalog &<br /> Student Borrowing Portal
          </h1>

          <p className="text-gray-500 text-[13px] leading-relaxed mb-6 font-medium">
            Real-time physical copy tracking, online book renewal, hold requests, and course reserve allocations.
          </p>

          {/* Borrowing Rule Inner Card */}
          <div className="border border-gray-100 shadow-sm rounded-2xl p-5 relative overflow-hidden bg-white">
            <div className="absolute right-6 top-4 bottom-4 w-px bg-gray-100"></div>

            <div className="mb-4">
              <h3 className="text-[10px] font-bold text-gray-400 tracking-wider uppercase mb-1">Your Borrowing Rule</h3>
              {/* Neutral background - NO yellow background */}
              <span className="inline-block bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-extrabold px-2.5 py-0.5 rounded uppercase">
                {roleLabel ?? '—'}
              </span>
            </div>

            <div className="mb-4">
              <h3 className="text-[10px] font-bold text-gray-400 tracking-wider uppercase mb-1">Loan Privilege Limit</h3>
              <p className="font-extrabold text-[#0f172a] text-[15px]">
                {!summary
                  ? 'Loading your borrowing rule…'
                  : summary.can_borrow === false
                    ? 'Management account — borrowing not available'
                    : `${borrowLimit ? `Max ${borrowLimit} Books` : 'No borrowing limit'} (${loanDays}-Day Loan)`}
              </p>
            </div>

            <div>
              <h3 className="text-[10px] font-bold text-gray-400 tracking-wider uppercase mb-1">Overdue Late Fine</h3>
              <p className="font-extrabold text-[#0f172a] text-[15px]">
                {peso(dailyRate)} / Day
              </p>
            </div>
          </div>
        </div>

        {/* 2. Stats Dashboard (Vertical Stack for Mobile) */}
        <div className="flex flex-col gap-3">
          {loading ? (
            Array.from({ length: isLibrarian ? 5 : 3 }).map((_, i) => (
              <div key={i} className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100 animate-pulse">
                <div className="w-12 h-12 rounded-xl bg-gray-200 shrink-0"></div>
                <div className="flex flex-col gap-1.5 w-full py-1">
                  <div className="h-2.5 bg-gray-200 rounded w-1/2"></div>
                  <div className="h-5 bg-gray-200 rounded w-1/3 mt-0.5"></div>
                </div>
              </div>
            ))
          ) : (
            <>
              {/* Total Titles */}
              <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
                <div className="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center text-gray-500 shrink-0">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                  </svg>
                </div>
                <div>
                  <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Total Titles</div>
                  <div className="text-xl font-extrabold text-[#0f172a]">{catalogCount}</div>
                </div>
              </div>

              {/* Available */}
              <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
                <div className="w-12 h-12 rounded-xl bg-green-50 border border-green-100 flex items-center justify-center text-green-500 shrink-0">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div>
                  <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Available</div>
                  <div className="text-xl font-extrabold text-green-600">
                    {books.reduce((acc, b) => acc + b.available, 0)} <span className="text-sm text-gray-400 font-bold">/ {books.reduce((acc, b) => acc + b.total, 0)}</span>
                  </div>
                </div>
              </div>

              {/* Checked Out */}
              <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
                <div className="w-12 h-12 rounded-xl bg-orange-50 border border-orange-100 flex items-center justify-center text-orange-400 shrink-0">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div>
                  <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Checked Out</div>
                  <div className="text-xl font-extrabold text-orange-500">{checkedOutCount}</div>
                  {onHoldCount > 0 && (
                    <div className="text-[9px] font-bold text-gray-400 mt-0.5">+{onHoldCount} held for pickup</div>
                  )}
                </div>
              </div>

              {/* Overdue Copies (Only on SuperAdmin and Admin) */}
              {isLibrarian && (
                <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
                  <div className="w-12 h-12 rounded-xl bg-red-50 border border-red-100 flex items-center justify-center text-red-500 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                  </div>
                  <div>
                    <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Overdue Copies</div>
                    <div className="text-xl font-extrabold text-red-600">{circulation.filter(c => c.isOverdue).length}</div>
                  </div>
                </div>
              )}

              {/* Total System Fines (Only on SuperAdmin and Admin) */}
              {isLibrarian && (
                <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
                  <div className="w-12 h-12 rounded-xl bg-red-50 border border-red-100 flex items-center justify-center text-[#8B1A24] shrink-0 font-extrabold text-lg">
                    ₱
                  </div>
                  <div>
                    <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Total System Fines</div>
                    <div className="text-xl font-extrabold text-[#8B1A24]">{peso(finesData.total_outstanding)}</div>
                  </div>
                </div>
              )}
            </>
          )}
        </div>

        {/* 3. Navigation Buttons & Admin Quick Actions Card (Mobile) */}
        <div className="bg-white rounded-[1.5rem] p-3.5 shadow-sm border border-gray-100 flex flex-col gap-3.5">
          {/* Row 1: 3 Core Tabs for Everyone */}
          <div className="flex gap-2.5">
            <button
              onClick={() => setActiveTab('catalog')}
              className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'catalog'
                ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                }`}
            >
              <div className="flex items-center gap-1.5 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-[18px] h-[18px]">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <span className={`text-[10px] font-bold min-w-4 h-4 px-1 flex items-center justify-center rounded-full ${activeTab === 'catalog' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>
                  {catalogCount}
                </span>
              </div>
              <span className="text-[12px] font-extrabold text-center leading-tight">Catalog<br />Search</span>
            </button>

            {/* A Super Admin is management-only: no borrower side at all. */}
            {canBorrow && (
            <button
              onClick={() => setActiveTab('borrowing')}
              className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'borrowing'
                ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                }`}
            >
              <div className="flex items-center gap-1.5 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'borrowing' ? 'text-white' : 'text-orange-400'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                </svg>
                <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'borrowing' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-700'}`}>{activeLoanCount}</span>
              </div>
              <span className="text-[12px] font-extrabold text-center leading-tight">My<br />Borrowing</span>
            </button>
            )}

            <button
              onClick={() => setActiveTab('reserves')}
              className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'reserves'
                ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                }`}
            >
              <div className="flex items-center gap-1.5 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'reserves' ? 'text-white' : 'text-blue-400'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
                </svg>
                {reserveBadgeCount !== null && (
                  <span className={`text-[10px] font-bold min-w-4 h-4 px-1 flex items-center justify-center rounded-full ${activeTab === 'reserves' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-700'}`}>{reserveBadgeCount}</span>
                )}
              </div>
              <span className="text-[12px] font-extrabold text-center leading-tight">Course<br />Reserves</span>
            </button>
          </div>

          {/* Row 2: Admin Quick Actions (ONLY on SuperAdmin and Admin, NO yellow background) */}
          {isLibrarian && (
            <>
              <div className="w-full border-t-2 border-dashed border-gray-100"></div>

              <div className="flex gap-2.5">
                <button
                  onClick={() => setActiveTab('circulation')}
                  className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'circulation'
                    ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                    : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                    }`}
                >
                  <div className="flex items-center gap-1.5 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'circulation' ? 'text-white' : 'text-green-500'}`}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    <span className={`text-[10px] font-bold min-w-4 h-4 px-1 flex items-center justify-center rounded-full ${activeTab === 'circulation' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>{activeCirculationCount}</span>
                  </div>
                  <span className="text-[11px] font-extrabold text-center leading-tight">Circulation<br />Desk</span>
                </button>

                <button
                  onClick={() => setActiveTab('fines')}
                  className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'fines'
                    ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                    : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                    }`}
                >
                  <div className="flex items-center gap-1.5 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'fines' ? 'text-white' : 'text-red-500'}`}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span className={`text-[10px] font-bold min-w-4 h-4 px-1 flex items-center justify-center rounded-full ${activeTab === 'fines' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>{finesQueueCount}</span>
                  </div>
                  <span className="text-[11px] font-extrabold text-center leading-tight">Admin Fines<br />& Queue</span>
                </button>

                {/* Add Title Button (Positioned beside Circulation Desk & Admin Fines, NO yellow background) */}
                <button
                  type="button"
                  onClick={() => setActiveTab('add_title')}
                  className={`flex-1 flex flex-col items-center justify-center py-3.5 px-1 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'add_title'
                    ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                    : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                    }`}
                >
                  <div className="flex items-center gap-1.5 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'add_title' ? 'text-white' : 'text-slate-700'}`}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                  </div>
                  <span className="text-[11px] font-extrabold text-center leading-tight">Add Title<br />& Copies</span>
                </button>
              </div>
            </>
          )}
        </div>

        {/* 4. Active Tab Content for Mobile */}
        <div className="flex flex-col gap-4 w-full">
          {activeTab === 'catalog' && (
            <>
              {/* 5. Search & Filter Section (Mobile) */}
              <div className="bg-white rounded-[2rem] p-5 shadow-sm border border-gray-100 mt-2">
                <div className="flex items-center gap-2 mb-4 border-b border-gray-100 pb-3">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4 text-[#8B1A24]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                  </svg>
                  <h2 className="text-[11px] font-extrabold text-[#1e293b] tracking-wider uppercase">Library Catalog Search & Discovery</h2>
                </div>

                <div className="flex flex-col sm:flex-row sm:items-end gap-3 mb-4">
                <div className="flex-1 min-w-0">
                  <label className="block text-[10px] font-extrabold text-[#1e293b] tracking-wider uppercase mb-2">
                    Search Book Titles, Authors, ISBNs, or Call Numbers
                  </label>
                  <div className="relative">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input
                      type="text"
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      placeholder="Search title, author, ISBN, location..."
                      className="w-full bg-gray-50 border border-gray-200 rounded-xl py-2.5 pl-9 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-gray-200 text-gray-700"
                    />
                  </div>
                </div>

                <div className="w-full sm:w-[240px] shrink-0">
                  <label htmlFor="catalog-category-mobile" className="block text-[10px] font-extrabold text-[#1e293b] tracking-wider uppercase mb-2">
                    Filter by Category:
                  </label>
                  <CategorySelect
                    id="catalog-category-mobile"
                    ariaLabel="Filter by category"
                    value={selectedCategory}
                    onChange={setSelectedCategory}
                    categories={categories}
                    includeAll
                    className="w-full bg-gray-50 border border-gray-200 rounded-xl py-2.5 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-gray-200 text-gray-700 font-bold cursor-pointer"
                  />
                </div>
                </div>
              </div>

              {/* 6. Book List (Mobile Single Column) */}
              <div className="flex flex-col gap-3 mt-2">
                {loading ? (
                  Array.from({ length: 3 }).map((_, i) => (
                    <div key={i} className="bg-white rounded-[1.5rem] p-4 shadow-sm border border-gray-100 flex flex-col gap-4 animate-pulse">
                      <div className="flex gap-4">
                        <div className="w-[88px] h-[104px] shrink-0 rounded-xl bg-gray-200"></div>
                        <div className="flex flex-col flex-1 gap-2 py-1">
                          <div className="h-3 bg-gray-200 rounded w-1/3 mb-1"></div>
                          <div className="h-4 bg-gray-200 rounded w-3/4 mb-1"></div>
                          <div className="h-3 bg-gray-200 rounded w-1/2"></div>
                          <div className="h-2 bg-gray-200 rounded w-1/3"></div>
                          <div className="mt-auto h-3 bg-gray-200 rounded w-2/3"></div>
                        </div>
                      </div>
                      <div className="flex items-center justify-between border-t border-gray-100 pt-3">
                        <div className="h-6 bg-gray-200 rounded-full w-1/2"></div>
                        <div className="h-7 bg-gray-200 rounded-full w-24"></div>
                      </div>
                    </div>
                  ))
                ) : filteredBooks.length === 0 ? (
                  <div className="bg-white rounded-[1.5rem] p-8 text-center border border-gray-100 shadow-sm">
                    <p className="text-gray-500 font-bold text-xs">No books found matching your search.</p>
                    <button
                      onClick={() => { setSearchQuery(''); setSelectedCategory(''); }}
                      className="mt-3 px-4 py-2 bg-[#8B1A24] text-white text-[11px] font-extrabold rounded-xl cursor-pointer"
                    >
                      Reset Filters
                    </button>
                  </div>
                ) : (
                  filteredBooks.map(book => (
                    <div key={book.id} className="bg-white rounded-[1.5rem] p-4 shadow-sm border border-gray-100 flex flex-col gap-4">
                      <div className="flex gap-4">
                        <div className="w-[72px] aspect-[3/4] shrink-0 rounded-xl overflow-hidden shadow-sm border border-slate-200 bg-slate-100 flex items-center justify-center relative">
                          <BookCover src={book.image} alt={book.title} />
                        </div>
                        <div className="flex flex-col flex-1 min-w-0">
                          <div className="flex flex-wrap gap-1.5 mb-2">
                            {book.categories.map((cat, i) => (
                              <span key={i} className={`text-[8px] font-extrabold uppercase px-1.5 py-0.5 rounded ${cat === 'COURSE RESERVE' ? 'bg-amber-100 text-amber-800' : 'bg-blue-50 text-blue-700'}`}>
                                {cat}
                              </span>
                            ))}
                          </div>
                          <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1">{book.title}</h3>
                          <p className="text-[11px] text-gray-500 font-medium mb-1 truncate">by {book.author}</p>
                          <p className="text-[10px] text-gray-400 font-medium mb-2 truncate">ISBN: {book.isbn}</p>
                          <div className="flex items-start gap-1 text-gray-500 mt-auto">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 shrink-0 mt-0.5 text-[#8B1A24]">
                              <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                              <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                            <span className="text-[10px] font-bold leading-tight text-[#1e293b]">{book.location}</span>
                          </div>
                        </div>
                      </div>

                      <div className="flex items-center justify-between border-t border-gray-100 pt-3">
                        <div className="bg-green-50 text-green-700 border border-green-100 px-2.5 py-1 rounded-full flex items-center gap-1.5">
                          <div className="w-1.5 h-1.5 bg-green-500 rounded-full"></div>
                          <span className="text-[10px] font-extrabold">{book.available} of {book.total} Copies Available</span>
                        </div>
                        <button
                          onClick={() => setSelectedBook(book)}
                          className="bg-[#1e293b] text-white text-[11px] font-extrabold px-3 py-1.5 rounded-full flex items-center gap-1 hover:bg-[#0f172a] transition-colors cursor-pointer"
                        >
                          View Copies
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" className="w-3 h-3">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                          </svg>
                        </button>
                      </div>
                    </div>
                  ))
                )}
              </div>

              {/* Pagination on phones and tablets too — the catalog is not
                  limited to the first page here either. */}
              {pagination.last_page > 1 && (
                <div className="bg-white rounded-[1.5rem] px-5 py-3.5 shadow-sm border border-gray-100 flex flex-col gap-2.5 items-center">
                  <span className="text-[11.5px] font-semibold text-slate-500">
                    Page {pagination.current_page} of {pagination.last_page} · {pagination.total} titles
                  </span>
                  <div className="flex items-center gap-2 w-full">
                    <button
                      onClick={() => setPage(p => Math.max(1, p - 1))}
                      disabled={pagination.current_page <= 1}
                      className="flex-1 px-3.5 py-2 rounded-lg border border-slate-200 text-[11.5px] font-extrabold text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                    >
                      Previous
                    </button>
                    <button
                      onClick={() => setPage(p => Math.min(pagination.last_page, p + 1))}
                      disabled={pagination.current_page >= pagination.last_page}
                      className="flex-1 px-3.5 py-2 rounded-lg border border-slate-200 text-[11.5px] font-extrabold text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                    >
                      Next
                    </button>
                  </div>
                </div>
              )}
            </>
          )}

          {activeTab === 'borrowing' && canBorrow && (
            <div className="flex flex-col gap-3 mt-2">
              <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 text-center">
                <div className="flex items-center justify-center gap-2 mb-2">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 text-[#8B1A24]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                  </svg>
                  <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Your Active Book Loans ({activeLoanCount})</h2>
                </div>
                <p className="text-gray-500 text-[11px] leading-relaxed mb-4 font-medium">
                  Books currently checked out under your student profile ({user?.username || '2012-00000-SYS'}).
                </p>
                <div className="inline-block bg-gray-100 text-[#0f172a] text-[10px] font-extrabold px-3 py-1.5 rounded-lg mb-5">
                  Max Allowed: {activeLoanCount} / {borrowLimit ?? '∞'} Books
                </div>
                {overdueCount > 0 && (
                  <div className="self-start sm:self-auto bg-rose-600 text-white text-[10px] font-extrabold px-3 py-1.5 rounded-lg shrink-0">
                    {overdueCount} Overdue
                  </div>
                )}

                {myLoans.length === 0 ? (
                  <div className="border border-gray-100 rounded-[1.25rem] p-6 flex flex-col items-center justify-center bg-gray-50/30">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-8 h-8 text-gray-400 mb-3">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                    </svg>
                    <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No active book loans at this time.</h3>
                    <p className="text-gray-500 text-[11px] font-medium leading-relaxed max-w-[250px]">
                      Browse the catalog to find available physical books for research or coursework.
                    </p>
                  </div>
                ) : (
                  <div className="flex flex-col gap-3">
                    {myLoans.map(loan => (
                      <div
                        key={loan.transaction_id}
                        className="border border-emerald-200 bg-emerald-50/30 rounded-xl p-4 text-left shadow-xs cursor-pointer hover:border-emerald-300 transition-colors"
                        onClick={() => {
                          const foundBook = books.find(b => b.id === loan.book_copy?.book_id);
                          if (foundBook) setSelectedBook(foundBook);
                        }}
                      >
                        <h4 className="font-extrabold text-[#0f172a] text-[13px] mb-1 truncate" title={loan.book_copy?.book?.book_title}>
                          {loan.book_copy?.book?.book_title}
                        </h4>
                        <p className="text-[11px] text-slate-500 mb-2 font-medium">
                          Accession: <span className="font-extrabold text-[#0f172a]">{loan.book_copy?.accession_number || `CPY-${loan.book_copy?.copy_id}`}</span>
                        </p>
                        <div className="flex items-center justify-between mt-3 pt-3 border-t border-emerald-100/50">
                          <span className={`text-[10px] font-extrabold uppercase tracking-wider `}>
                            Due: {new Date(loan.due_date).toLocaleDateString()}
                          </span>
                          {loan.is_overdue && (
                            <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded bg-rose-600 text-white">
                              Overdue {loan.days_overdue}d
                            </span>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 text-center">
                <div className="flex items-center justify-center gap-1.5 mb-2">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 text-[#8B1A24]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Library Fines & Clearance Ledger</h2>
                </div>
                <div className="inline-block bg-[#d1fae5] text-[#065f46] text-[10px] font-extrabold px-3 py-1 rounded-md mb-4 uppercase tracking-wider">
                  Clear Account
                </div>

                <div className="w-full border-t border-gray-100 mb-4"></div>

                <div className="border border-gray-100 rounded-[1.25rem] p-5 text-left bg-white mb-3 shadow-sm">
                  <div className="flex justify-between items-center mb-3">
                    <span className="text-[12px] font-extrabold text-gray-500">Unpaid Library Fines Balance:</span>
                    <span className="text-[18px] font-extrabold text-[#8B1A24]">{peso(myBalance)}</span>
                  </div>
                  <p className="text-gray-500 text-[10px] font-medium leading-relaxed">
                    Calculated at {peso(dailyRate)} per overdue calendar day. Fines are charged when the librarian checks the book in, and must be cleared before borrowing again.
                  </p>
                </div>

                <div className="border border-[#bae6fd] bg-[#f0f9ff] rounded-[1rem] p-4 text-left flex gap-3 items-start">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5 shrink-0 text-[#0284c7]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                  </svg>
                  <p className="text-[10px] text-[#0369a1] font-medium leading-relaxed">
                    Payments can be settled at the University Cashier (Ground Floor Main Admin) or through online GCash/Bank Deposit clearance verification.
                  </p>
                </div>
              </div>

              <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 text-center">
                <div className="flex items-center justify-center gap-2 mb-2">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 text-orange-500" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Your Book Hold Requests ({myHolds.length})</h2>
                </div>
                <p className="text-gray-500 text-[10px] font-extrabold uppercase tracking-wider mb-4">
                  Queue Status
                </p>

                <div className="w-full border-t border-gray-100 mb-4"></div>

                {loading ? (
                  <div className="border border-gray-100 rounded-[1.25rem] p-6 mb-3 animate-pulse">
                    <div className="h-3 bg-gray-200 rounded w-1/2 mx-auto mb-2"></div>
                    <div className="h-2.5 bg-gray-100 rounded w-2/3 mx-auto"></div>
                  </div>
                ) : myHolds.length === 0 ? (
                  <div className="border border-gray-100 rounded-[1.25rem] p-6 flex flex-col items-center justify-center bg-gray-50/30 mb-3">
                    <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No active book hold requests.</h3>
                    <p className="text-gray-500 text-[11px] font-medium leading-relaxed">
                      When a book is out of stock, click &ldquo;View Copies&rdquo; to join the hold queue.
                    </p>
                  </div>
                ) : (
                  <div className="flex flex-col gap-2.5 mb-3">
                    {myHolds.map(hold => (
                      <div key={hold.id} className="border border-gray-200 rounded-[1.25rem] p-3.5 bg-white text-left">
                        <div className="flex items-start justify-between gap-2 mb-1">
                          <h4 className="font-extrabold text-[12px] text-[#0f172a] leading-tight flex-1">{hold.bookTitle}</h4>
                          <StatusBadge
                            status={hold.status === 'fulfilled' ? 'ready for pickup' : hold.status === 'pending_approval' ? 'pending approval' : 'queued'}
                            label={hold.pos}
                            className="shrink-0"
                          />
                        </div>
                        <p className="text-[10px] text-gray-500 font-medium mb-2.5">
                          Requested on {new Date(hold.date).toLocaleDateString()}
                        </p>
                        <button
                          onClick={() => handleCancelHold(hold.id)}
                          disabled={actionBusy === `cancel-hold-${hold.id}`}
                          className="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-extrabold py-2 rounded-xl transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                          {actionBusy === `cancel-hold-${hold.id}` ? <><Loader2 className="w-3 h-3 animate-spin mr-1.5 inline-block" />Cancelling...</> : 'Cancel Hold'}
                        </button>
                      </div>
                    ))}
                  </div>
                )}

                <div className="border border-amber-200 bg-[#fffbeb] rounded-[1rem] p-4 text-left flex gap-3 items-start">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5 shrink-0 text-[#b45309]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 0121 12z" />
                  </svg>
                  <p className="text-[10px] text-[#92400e] font-medium leading-relaxed">
                    Books held under "Ready for Pickup" are reserved at the Circulation Desk for 48 hours before proceeding to the next student in queue.
                  </p>
                </div>
              </div>

              {/* Borrowing history — card list rather than the desktop table, which
                  would overflow at phone widths. Same data, same ownership scoping. */}
              <div className="bg-white rounded-[2rem] p-5 shadow-sm border border-gray-100">
                <div className="flex items-center justify-between gap-2 mb-3">
                  <h3 className="font-extrabold text-[#0f172a] text-[13px] leading-tight">Your Borrowing History</h3>
                  <span className="bg-gray-100 text-gray-700 text-[10px] font-extrabold px-2.5 py-1 rounded-lg shrink-0">
                    {myHistory.length}
                  </span>
                </div>
                <div className="w-full border-t border-gray-100 mb-3"></div>

                {myHistory.length === 0 ? (
                  <p className="text-[11px] text-gray-500 italic text-center py-4">
                    You have not borrowed anything yet.
                  </p>
                ) : (
                  <div className="flex flex-col gap-2.5">
                    {myHistory.map(t => (
                      <div key={t.transaction_id} className="border border-gray-200 rounded-[1.25rem] p-3.5 bg-white shadow-sm">
                        <div className="flex items-start justify-between gap-2 mb-1">
                          <h4 className="font-extrabold text-[12px] text-[#0f172a] leading-tight flex-1">
                            {t.book_copy?.book?.book_title || '—'}
                          </h4>
                          {t.status === 'active' && t.is_overdue ? (
                            <span className="text-[8.5px] font-black uppercase px-2 py-0.5 rounded bg-rose-600 text-white shrink-0">
                              Overdue {t.days_overdue}d
                            </span>
                          ) : t.status === 'active' ? (
                            <span className="text-[8.5px] font-black uppercase px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 shrink-0">
                              On Loan
                            </span>
                          ) : (
                            <span className="text-[8.5px] font-black uppercase px-2 py-0.5 rounded bg-gray-100 text-gray-600 shrink-0">
                              Returned
                            </span>
                          )}
                        </div>
                        <p className="text-[10px] text-gray-500 font-medium">
                          Borrowed {t.date_borrowed ? new Date(t.date_borrowed).toLocaleDateString() : '—'}
                          {' · '}Due {t.due_date ? new Date(t.due_date).toLocaleDateString() : '—'}
                          {t.actual_return_date ? ` · Returned ${new Date(t.actual_return_date).toLocaleDateString()}` : ''}
                        </p>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          )}

          {activeTab === 'reserves' && (
            <>
              {isFaculty ? (
                <div className="mt-2">
                  <TeacherReservesView books={books} onSectionsLoaded={handleFacultySections} />
                </div>
              ) : (
                <>
                  <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 mt-2">
                    <div className="flex items-start gap-2 mb-2">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 shrink-0 mt-0.5 text-[#0284c7]" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
                      </svg>
                      <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Course Reserve Books &amp; Syllabus References</h2>
                    </div>
                    <p className="text-gray-500 text-[12px] leading-relaxed font-medium">
                      Textbooks set aside by professors for 2-Hour In-Library Desk Reference or Overnight study.
                    </p>
                  </div>

                  <div className="bg-white rounded-[2rem] p-5 shadow-sm border border-gray-100 mt-3">
                    <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-3 leading-tight">
                      {isLibrarian ? 'Registered Course Reserves & Professor Allocations' : 'Your Course Reserves'} ({reserves.length})
                    </h3>
                    <div className="w-full border-t border-gray-100 mb-4"></div>

                    {loading ? (
                      <div className="flex flex-col gap-3">
                        {Array.from({ length: 2 }).map((_, i) => (
                          <div key={i} className="border border-slate-200 rounded-[1.25rem] p-4 animate-pulse">
                            <div className="h-3 bg-slate-200 rounded w-1/3 mb-3"></div>
                            <div className="h-4 bg-slate-200 rounded w-2/3 mb-2"></div>
                            <div className="h-2.5 bg-slate-100 rounded w-1/2"></div>
                          </div>
                        ))}
                      </div>
                    ) : reserves.length === 0 ? (
                      <div className="border border-gray-100 rounded-[1.25rem] p-6 flex flex-col items-center justify-center bg-gray-50/30 text-center">
                        <h4 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No course reserves.</h4>
                        <p className="text-gray-500 text-[11px] font-medium leading-relaxed max-w-[260px]">
                          {isLibrarian
                            ? 'Reserves appear here when a teacher requests one for a section.'
                            : 'Your professors have not set aside any titles for your sections yet.'}
                        </p>
                      </div>
                    ) : (
                      <div className="flex flex-col gap-3 md:grid md:grid-cols-2">
                        {reserves.map(reserve => (
                          isLibrarian ? (
                            <AdminReserveCard
                              key={reserve.id}
                              reserve={reserve}
                              onUpdateStatus={handleUpdateReserveStatus}
                              onAllocate={handleAllocateCopies}
                              busyKey={actionBusy}
                            />
                          ) : (
                            <StudentCourseReserveCard
                              key={reserve.id}
                              reserve={reserve}
                              onViewClassmates={handleViewClassmates}
                              onRequestBorrow={handleRequestReserveCopy}
                            />
                          )
                        ))}
                      </div>
                    )}
                  </div>
                </>
              )}
            </>
          )}

          {/* Circulation Desk (Mobile) - Only on SuperAdmin and Admin */}
          {activeTab === 'circulation' && isLibrarian && (
            <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 mt-2">
              <h2 className="text-xl font-extrabold text-[#0f172a] mb-2 tracking-tight">Circulation Log &amp; Active Loans</h2>
              <p className="text-gray-500 text-[12px] leading-relaxed mb-4 font-medium">
                Track individual physical copies using unique ABC-LIB accession numbers.
              </p>
              <div className="flex justify-center mb-6">
                <span className="bg-gray-100 text-gray-600 text-[10px] font-extrabold px-3 py-1.5 rounded-md uppercase tracking-wider">
                  Circulation Desk #01
                </span>
              </div>

              {/* Mobile Checkout Form */}
              <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-6">
                <h3 className="font-extrabold text-[13px] text-[#0f172a] mb-3">Check-Out Book Copy</h3>
                <form className="flex flex-col gap-3" onSubmit={(e) => {
                  handleCheckout(e, checkoutData.userId, checkoutData.copyId);
                  setCheckoutData({ userId: '', copyId: '' });
                }}>
                  <input
                    type="text"
                    placeholder="Borrower User ID"
                    aria-label="Borrower User ID"
                    value={checkoutData.userId}
                    onChange={(e) => setCheckoutData({ ...checkoutData, userId: e.target.value })}
                    className="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-[12px] focus:ring-1 focus:ring-[#8B1A24]"
                    required
                  />
                  <input
                    type="text"
                    placeholder="Book Copy ID"
                    aria-label="Book Copy ID"
                    value={checkoutData.copyId}
                    onChange={(e) => setCheckoutData({ ...checkoutData, copyId: e.target.value })}
                    className="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-[12px] focus:ring-1 focus:ring-[#8B1A24]"
                    required
                  />
                  <button
                    type="submit"
                    disabled={actionBusy === 'checkout'}
                    className="w-full bg-[#8B1A24] text-white text-[12px] font-extrabold py-2.5 rounded-xl hover:bg-[#6b141c] transition-colors cursor-pointer shadow-xs disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {actionBusy === 'checkout' ? <><Loader2 className="w-3.5 h-3.5 animate-spin mr-1.5 inline-block" />Checking out...</> : 'Check Out'}
                  </button>
                </form>
              </div>

              {/* Same search and scope controls the desktop desk has. */}
              <div className="flex flex-col gap-2.5 mb-4">
                <input
                  type="text"
                  placeholder="Search by borrower ID, name, copy, or title…"
                  aria-label="Search circulation"
                  value={circulationSearch}
                  onChange={(e) => setCirculationSearch(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-[13px] focus:ring-1 focus:ring-emerald-500"
                />
                <div className="grid grid-cols-3 rounded-xl border border-slate-200 overflow-hidden">
                  {[['active', 'Active'], ['all', 'All History'], ['returned', 'Returned']].map(([value, label]) => (
                    <button
                      key={value}
                      onClick={() => setCirculationScope(value)}
                      aria-pressed={circulationScope === value}
                      className={`px-2 py-2.5 text-[11.5px] font-extrabold transition-colors cursor-pointer ${circulationScope === value
                        ? 'bg-[#1e293b] text-white'
                        : 'bg-white text-slate-600 hover:bg-slate-50'
                        }`}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </div>

              {loading ? (
                <div className="flex flex-col gap-4">
                  {Array.from({ length: 2 }).map((_, i) => (
                    <div key={i} className="border border-gray-200 rounded-[1.25rem] p-4 animate-pulse">
                      <div className="h-3.5 bg-gray-200 rounded w-2/3 mb-3"></div>
                      <div className="h-2.5 bg-gray-100 rounded w-1/2 mb-4"></div>
                      <div className="h-9 bg-gray-100 rounded-xl w-full"></div>
                    </div>
                  ))}
                </div>
              ) : filteredCirculation.length === 0 ? (
                <div className="border border-gray-100 rounded-[1.25rem] p-6 flex flex-col items-center justify-center bg-gray-50/30 text-center">
                  <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">
                    {circulationSearch ? 'No matching transactions.' : circulationScope === 'active' ? 'No active loans right now.' : 'No transactions in this view.'}
                  </h3>
                  <p className="text-gray-500 text-[11px] font-medium leading-relaxed max-w-[250px]">
                    {circulationSearch
                      ? 'Try a different borrower, copy or title.'
                      : 'Checked-out copies appear here until they are returned.'}
                  </p>
                </div>
              ) : (
                <div className="flex flex-col gap-4">
                  {filteredCirculation.map(item => (
                    <div key={item.id} className="border border-gray-200 rounded-[1.25rem] p-4 flex flex-col gap-3 bg-white">
                      <div className="flex justify-between items-start gap-2">
                        <div className="min-w-0">
                          <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1">{item.title}</h3>
                          <p className="text-[11px] text-gray-500 font-medium">by {item.author}</p>
                        </div>
                        <span className="border border-gray-200 bg-gray-50 text-gray-600 text-[9px] font-extrabold px-2 py-1 rounded shrink-0 font-mono">
                          {item.copyId}
                        </span>
                      </div>

                      <div className="w-full border-t border-gray-100 my-1"></div>

                      <div className="flex flex-col gap-1.5">
                        <p className="text-[11px] text-gray-600 font-medium">
                          Borrower: <span className="font-extrabold text-[#0f172a]">{item.borrower}</span> <span className="text-gray-500">(ID: {item.borrowerId})</span>
                        </p>

                        <div className="flex items-center gap-2 flex-wrap">
                          <StatusBadge status={item.status === 'returned' ? 'returned' : item.isOverdue ? 'overdue' : 'checked out'} />
                          {item.status === 'returned' ? (
                            <span className="text-[11px] font-bold text-slate-500">
                              Returned {item.returnedAt ? new Date(item.returnedAt).toLocaleDateString() : '—'}
                            </span>
                          ) : item.isOverdue ? (
                            <span className="text-[11px] font-extrabold text-rose-600">
                              Was due {item.dueDate ? new Date(item.dueDate).toLocaleDateString() : '—'}
                            </span>
                          ) : (
                            <span className="text-[11px] font-bold text-slate-500">
                              Due {item.dueDate ? new Date(item.dueDate).toLocaleDateString() : '—'}
                            </span>
                          )}
                        </div>
                      </div>

                      {item.status !== 'returned' && (
                        <button
                          onClick={(e) => handleCheckin(e, item.id)}
                          disabled={actionBusy === `checkin-${item.id}`}
                          className="w-full mt-1 bg-[#047857] hover:bg-[#065f46] text-white text-[12px] font-extrabold py-2.5 rounded-xl transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                          {actionBusy === `checkin-${item.id}` ? <><Loader2 className="w-3 h-3 animate-spin mr-1.5 inline-block" />Checking in...</> : 'Check-In Copy'}
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Admin Fines & Queue (Mobile) - Only on SuperAdmin and Admin */}
          {activeTab === 'fines' && isLibrarian && (
            <div className="flex flex-col gap-4 mt-2">
              <RenewalRequestsPanel
                requests={pendingRenewals}
                onDecide={handleDecideRenewal}
                busyKey={actionBusy}
                loading={loading || panelLoading}
              />

              <HoldQueuePanel
                requests={filteredHoldRequests}
                totalRequests={holdRequests.length}
                bookOptions={[...new Set(holdRequests.map(r => r.bookTitle))]}
                statusFilter={holdStatusFilter}
                setStatusFilter={setHoldStatusFilter}
                bookFilter={holdBookFilter}
                setBookFilter={setHoldBookFilter}
                search={holdSearchQuery}
                setSearch={setHoldSearchQuery}
                onAccept={handleAcceptHold}
                onCheckout={handleCheckout}
                onCancel={handleCancelHold}
                loading={loading || panelLoading}
              />

              <AdminFinesPanel finesData={finesData} onSettled={refreshCurrent} loading={loading || panelLoading} />
            </div>
          )}

          {/* Add Title & Physical Copies Tab (Mobile) - Only on SuperAdmin and Admin */}
          {activeTab === 'add_title' && isLibrarian && (
            <>
              <div className="bg-slate-50 p-1.5 rounded-xl border border-slate-200 flex flex-wrap gap-1 mb-4 shadow-xs">
                {[
                  ['inventory', 'Inventory', Package],
                  ['settings', 'Settings', Settings],
                ].map(([value, label, Icon]) => (
                  <button
                    key={value}
                    onClick={() => setManageSection(value)}
                    className={`flex-1 min-w-[6rem] py-2 px-3 rounded-lg text-[12px] font-extrabold transition-all cursor-pointer flex items-center justify-center gap-1.5 ${
                      manageSection === value
                      ? 'bg-white text-slate-800 shadow-sm border border-slate-200'
                      : 'bg-transparent text-slate-500 hover:bg-slate-100 hover:text-slate-700 border border-transparent'
                      }`}
                  >
                    <Icon className="w-3.5 h-3.5" />
                    {label}
                  </button>
                ))}
              </div>

              {manageSection === 'settings' && <LibrarySettingsPanel />}

              {manageSection === 'inventory' && (
                <>
              <AdminInventoryPanel books={books} onChanged={refreshCurrent} />
              <div className="h-4" />
              <div className="bg-white rounded-[2rem] p-5 sm:p-6 shadow-sm border border-slate-100 mt-2">
                <div className="mb-5 border-b border-slate-100 pb-4">
                  <span className="inline-block bg-slate-100 text-slate-600 border border-slate-200 text-[9px] font-black uppercase px-2 py-0.5 rounded tracking-wider mb-2">
                    LIBRARY INVENTORY
                  </span>
                  <h2 className="text-[18px] font-black text-[#0f172a] leading-tight">Register New Title</h2>
                </div>

                <AddBookForm 
                  newBook={newBook} 
                  setNewBook={setNewBook} 
                  handleAddBook={handleAddBook} 
                  onCancel={() => setActiveTab('catalog')} 
                  actionBusy={actionBusy}
                categories={categories}
                onCategoriesChanged={registerCategory}
                canManageCategories={isLibrarian}
                />
              </div>
                </>
              )}
            </>
          )}
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 🖥️ DESKTOP VIEW (lg:): Dashboard Mode Exactly Matching User Mockup       */}
      {/* ========================================================================= */}
      <div className="hidden lg:flex lg:flex-col gap-4.5 w-full max-w-7xl mx-auto p-4">

        {/* 1. Desktop Header Card */}
        <div className="bg-white rounded-[1.25rem] p-5 lg:p-6 shadow-xs border border-slate-200/70">
          <div className="flex items-center gap-2 mb-2.5">
            <span className="bg-[#8B1A24] text-white text-[9.5px] font-black tracking-wider uppercase px-2.5 py-1 rounded">
              LIBRARY & CIRCULATION SYSTEM
            </span>
          </div>

          <h1 className="text-[24px] lg:text-[26px] font-extrabold text-[#0f172a] tracking-tight leading-tight mb-1">
            Library Catalog & Student Borrowing Portal
          </h1>

          <p className="text-slate-400 text-[11.5px] font-medium mb-4">
            Real-time physical copy tracking, online book renewal, hold requests, and course reserve allocations.
          </p>

          {/* Borrowing Rule Inner Box (3 Columns) */}
          <div className="border border-slate-200/80 rounded-xl p-4 bg-white grid grid-cols-1 md:grid-cols-3 gap-4 divide-y md:divide-y-0 md:divide-x divide-slate-100">
            <div className="md:pr-4">
              <h3 className="text-[9.5px] font-extrabold text-slate-400 tracking-wider uppercase mb-1">YOUR BORROWING RULE</h3>
              {/* Neutral pill - NO yellow background */}
              <span className="inline-block bg-slate-100 text-slate-800 border border-slate-200 text-[10.5px] font-extrabold px-2.5 py-0.5 rounded uppercase">
                {roleLabel ?? '—'}
              </span>
            </div>

            <div className="pt-3 md:pt-0 md:px-5">
              <h3 className="text-[9.5px] font-extrabold text-slate-400 tracking-wider uppercase mb-1">LOAN PRIVILEGE LIMIT</h3>
              <p className="font-extrabold text-[#0f172a] text-[13px]">
                {!summary
                  ? 'Loading your borrowing rule…'
                  : summary.can_borrow === false
                    ? 'Management account — borrowing not available'
                    : `${borrowLimit ? `Max ${borrowLimit} Books` : 'No borrowing limit'} (${loanDays}-Day Loan)`}
              </p>
            </div>

            <div className="pt-3 md:pt-0 md:pl-5">
              <h3 className="text-[9.5px] font-extrabold text-slate-400 tracking-wider uppercase mb-1">OVERDUE LATE FINE</h3>
              <p className="font-extrabold text-[#0f172a] text-[13px]">
                {peso(dailyRate)} / Day
              </p>
            </div>
          </div>
        </div>

        {/* 2. Desktop Key Metric Stat Cards */}
        <div className="flex flex-col gap-3">
          <div className={`grid grid-cols-1 sm:grid-cols-2 ${isLibrarian ? 'lg:grid-cols-4' : 'lg:grid-cols-3'} gap-3.5`}>
            {loading ? (
              Array.from({ length: isLibrarian ? 4 : 3 }).map((_, i) => (
                <div key={i} className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70 animate-pulse">
                  <div className="w-10 h-10 rounded-xl bg-slate-200 shrink-0"></div>
                  <div className="flex flex-col gap-1.5 w-full py-1">
                    <div className="h-2 bg-slate-200 rounded w-1/2"></div>
                    <div className="h-5 bg-slate-200 rounded w-1/4 mt-0.5"></div>
                  </div>
                </div>
              ))
            ) : (
              <>
                {/* Total Titles */}
                <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
                  <div className="w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor" className="w-5 h-5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                    </svg>
                  </div>
                  <div>
                    <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">TOTAL TITLES</div>
                    <div className="text-[22px] font-black text-[#0f172a] leading-none mt-0.5">{catalogCount}</div>
                  </div>
                </div>

                {/* Available */}
                <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
                  <div className="w-10 h-10 rounded-xl bg-emerald-50/80 border border-emerald-100 flex items-center justify-center text-emerald-500 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                  <div>
                    <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">AVAILABLE</div>
                    <div className="text-[22px] font-black text-emerald-600 leading-none mt-0.5">
                      {books.reduce((acc, b) => acc + b.available, 0)} <span className="text-xs text-slate-400 font-bold">/ {books.reduce((acc, b) => acc + b.total, 0)}</span>
                    </div>
                  </div>
                </div>

                {/* Checked Out */}
                <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
                  <div className="w-10 h-10 rounded-xl bg-amber-50/80 border border-amber-100 flex items-center justify-center text-amber-500 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                  <div>
                    <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">CHECKED OUT</div>
                    <div className="text-[22px] font-black text-amber-500 leading-none mt-0.5">{checkedOutCount}</div>
                    {onHoldCount > 0 && (
                      <div className="text-[9px] font-bold text-slate-400 mt-0.5">+{onHoldCount} held for pickup</div>
                    )}
                  </div>
                </div>

                {/* Overdue Copies (Only on SuperAdmin and Admin) */}
                {isLibrarian && (
                  <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
                    <div className="w-10 h-10 rounded-xl bg-rose-50/80 border border-rose-100 flex items-center justify-center text-rose-500 shrink-0">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                    </div>
                    <div>
                      <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">OVERDUE COPIES</div>
                      <div className="text-[22px] font-black text-rose-600 leading-none mt-0.5">{circulation.filter(c => c.isOverdue).length}</div>
                    </div>
                  </div>
                )}
              </>
            )}
          </div>

          {/* Row 2: Total System Fines (Only on SuperAdmin and Admin) */}
          {isLibrarian && (
            <div className="w-full sm:w-64">
              {loading ? (
                <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70 animate-pulse">
                  <div className="w-10 h-10 rounded-xl bg-slate-200 shrink-0"></div>
                  <div className="flex flex-col gap-1.5 w-full py-1">
                    <div className="h-2 bg-slate-200 rounded w-1/2"></div>
                    <div className="h-5 bg-slate-200 rounded w-1/2 mt-0.5"></div>
                  </div>
                </div>
              ) : (
                <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
                  <div className="w-10 h-10 rounded-xl bg-rose-50/80 border border-rose-100 flex items-center justify-center text-rose-600 shrink-0 font-black text-base">
                    ₱
                  </div>
                  <div>
                    <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">TOTAL SYSTEM FINES</div>
                    <div className="text-[22px] font-black text-[#8B1A24] leading-none mt-0.5">{peso(finesData.total_outstanding)}</div>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* 3. Desktop Navigation Tabs Card */}
        <div className="bg-white rounded-[1.25rem] p-3 shadow-xs border border-slate-200/70 flex flex-col gap-2.5">
          {/* Row 1: 3 Main Tabs for Everyone */}
          <div className={`grid grid-cols-1 gap-2.5 ${canBorrow ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
            {/* Catalog Search */}
            <button
              onClick={() => setActiveTab('catalog')}
              className={`flex items-center justify-center gap-1.5 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'catalog'
                ? 'bg-[#1e293b] text-white shadow-xs'
                : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                }`}
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4">
                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
              </svg>
              {catalogCount > 0 && (
              <span className={`text-[9px] font-bold min-w-4 h-4 px-1 flex items-center justify-center rounded-full ${activeTab === 'catalog' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600'}`}>
                {catalogCount}
              </span>
            )}
              <span>Catalog Search</span>
            </button>

            {/* My Borrowing — hidden for a management-only account. */}
            {canBorrow && (
            <button
              onClick={() => setActiveTab('borrowing')}
              className={`flex items-center justify-center gap-1.5 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'borrowing'
                ? 'bg-[#1e293b] text-white shadow-xs'
                : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                }`}
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'borrowing' ? 'text-white' : 'text-amber-500'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
              </svg>
              {activeLoanCount > 0 && (
              <span className={`text-[9px] font-bold min-w-4 h-4 px-1 flex items-center justify-center rounded-full ${activeTab === 'borrowing' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600'}`}>
                {activeLoanCount}
              </span>
            )}
              <span>My Borrowing</span>
            </button>
            )}

            {/* Course Reserves */}
            <button
              onClick={() => setActiveTab('reserves')}
              className={`flex items-center justify-center gap-1.5 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'reserves'
                ? 'bg-[#1e293b] text-white shadow-xs'
                : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                }`}
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'reserves' ? 'text-white' : 'text-sky-500'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
              </svg>
              {(reserveBadgeCount !== null && reserveBadgeCount > 0) && (
                <span className={`text-[9px] font-bold min-w-4 h-4 px-1 flex items-center justify-center rounded-full ${activeTab === 'reserves' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600'}`}>
                  {reserveBadgeCount}
                </span>
              )}
              <span>Course Reserves</span>
            </button>
          </div>

          {/* Row 2: Admin Tabs + Add Title Button (Only on SuperAdmin and Admin, NO yellow background) */}
          {isLibrarian && (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
              {/* Circulation Desk */}
              <button
                onClick={() => setActiveTab('circulation')}
                className={`flex items-center justify-center gap-2 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'circulation'
                  ? 'bg-[#1e293b] text-white shadow-xs'
                  : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                  }`}
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'circulation' ? 'text-white' : 'text-emerald-500'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                <span>Circulation Desk {activeCirculationCount > 0 && `(${activeCirculationCount})`}</span>
              </button>

              {/* Admin Fines & Queue */}
              <button
                onClick={() => setActiveTab('fines')}
                className={`flex items-center justify-center gap-2 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'fines'
                  ? 'bg-[#1e293b] text-white shadow-xs'
                  : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                  }`}
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'fines' ? 'text-white' : 'text-rose-500'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>Admin Fines & Queue {finesQueueCount > 0 && `(${finesQueueCount})`}</span>
              </button>

              {/* Add Title & Copies */}
              <button
                type="button"
                onClick={() => setActiveTab('add_title')}
                className={`flex items-center justify-center gap-2 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer active:scale-[0.99] ${activeTab === 'add_title'
                  ? 'bg-[#1e293b] text-white shadow-xs'
                  : 'bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-xs'
                  }`}
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'add_title' ? 'text-white' : 'text-slate-700'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>Add Title & Copies</span>
              </button>
            </div>
          )}
        </div>

        {/* 4. Desktop Tab Content Area */}
        <div className="flex flex-col gap-4 w-full">
          {activeTab === 'catalog' && (
            <>
              {/* Search & Filter Section (Desktop) */}
              <div className="bg-white rounded-[1.25rem] p-5 shadow-xs border border-slate-200/70">
                <div className="flex items-center gap-2 mb-3.5 pb-2.5 border-b border-slate-100">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4 text-[#8B1A24]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                  </svg>
                  <h2 className="text-[11px] font-black text-[#0f172a] tracking-wider uppercase">
                    LIBRARY CATALOG SEARCH & DISCOVERY
                  </h2>
                </div>

                <div className="flex flex-col sm:flex-row sm:items-end gap-3 mb-3.5">
                <div className="flex-1 min-w-0">
                  <label className="block text-[9.5px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                    SEARCH BOOK TITLES, AUTHORS, ISBNS, OR CALL NUMBERS
                  </label>
                  <div className="relative">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input
                      type="text"
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      placeholder="Search title, author, ISBN, location..."
                      className="w-full bg-[#f8fafc] border border-slate-200/80 rounded-xl py-2 pl-9 pr-4 text-[12px] text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
                    />
                    {searchQuery && (
                      <button
                        onClick={() => setSearchQuery('')}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold cursor-pointer"
                      >
                        Clear
                      </button>
                    )}
                  </div>
                </div>

                <div className="w-full sm:w-[240px] shrink-0">
                  <label htmlFor="catalog-category-desktop" className="block text-[9.5px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                    FILTER BY CATEGORY:
                  </label>
                  <CategorySelect
                    id="catalog-category-desktop"
                    ariaLabel="Filter by category"
                    value={selectedCategory}
                    onChange={setSelectedCategory}
                    categories={categories}
                    includeAll
                    className="w-full bg-[#f8fafc] border border-slate-200/80 rounded-xl py-2 px-3 text-[12px] text-slate-700 focus:outline-none focus:ring-1 focus:ring-slate-300 font-bold cursor-pointer"
                  />
                </div>
                </div>
              </div>

              {/* Book Cards Grid - 3 Columns Exactly Matching User Mockup */}
              {loading ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {Array.from({ length: 6 }).map((_, i) => (
                    <div key={i} className="bg-white rounded-[1.25rem] p-4 shadow-xs border border-slate-200/70 flex flex-col justify-between animate-pulse">
                      <div className="flex gap-3.5">
                        <div className="w-[76px] h-[92px] shrink-0 rounded-lg bg-slate-200"></div>
                        <div className="flex flex-col flex-1 gap-1.5 py-1">
                          <div className="h-2.5 bg-slate-200 rounded w-1/3 mb-1"></div>
                          <div className="h-3.5 bg-slate-200 rounded w-3/4 mb-0.5"></div>
                          <div className="h-2.5 bg-slate-200 rounded w-1/2"></div>
                          <div className="h-2 bg-slate-200 rounded w-1/3"></div>
                          <div className="mt-auto h-2.5 bg-slate-200 rounded w-2/3"></div>
                        </div>
                      </div>
                      <div className="flex items-center justify-between border-t border-slate-100 pt-2.5 mt-3">
                        <div className="h-5 bg-slate-200 rounded-full w-1/2"></div>
                        <div className="h-6 bg-slate-200 rounded-full w-20"></div>
                      </div>
                    </div>
                  ))}
                </div>
              ) : filteredBooks.length === 0 ? (
                <div className="bg-white rounded-[1.25rem] p-10 text-center border border-slate-200/70 shadow-xs">
                  <p className="text-slate-500 font-bold text-xs">No books found matching your search.</p>
                  <button
                    onClick={() => { setSearchQuery(''); setSelectedCategory(''); }}
                    className="mt-2.5 px-3 py-1.5 bg-[#8B1A24] text-white text-[11px] font-extrabold rounded-lg cursor-pointer"
                  >
                    Reset Filters
                  </button>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {filteredBooks.map((book) => (
                    <div key={book.id} className="bg-white rounded-[1.25rem] p-4 shadow-xs border border-slate-200/70 flex flex-col justify-between hover:shadow-sm transition-all">
                      <div className="flex gap-3.5">
                        <div className="w-[72px] aspect-[3/4] shrink-0 rounded-lg overflow-hidden border border-slate-200 bg-slate-100 shadow-xs flex items-center justify-center relative">
                          <BookCover src={book.image} alt={book.title} />
                        </div>

                        <div className="flex flex-col flex-1 min-w-0">
                          <div className="flex flex-wrap gap-1 mb-1">
                            {book.categories.map((cat, i) => (
                              <span
                                key={i}
                                className={`text-[8px] font-black uppercase px-1.5 py-0.5 rounded tracking-wider ${cat === 'COURSE RESERVE'
                                  ? 'bg-amber-100 text-amber-800'
                                  : 'bg-[#e0f2fe] text-[#0284c7]'
                                  }`}
                              >
                                {cat}
                              </span>
                            ))}
                          </div>

                          <h3 className="font-extrabold text-[12.5px] text-[#0f172a] leading-snug mb-0.5 line-clamp-2">
                            {book.title}
                          </h3>
                          <p className="text-[10.5px] text-slate-400 font-medium truncate mb-0.5">
                            by {book.author}
                          </p>
                          <p className="text-[9.5px] text-slate-400 font-medium truncate mb-1.5">
                            ISBN: {book.isbn}
                          </p>

                          <div className="flex items-start gap-1 text-slate-500 mt-auto">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 shrink-0 mt-0.5 text-[#8B1A24]">
                              <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                              <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                            <span className="text-[10px] font-bold leading-tight text-slate-600 truncate">
                              {book.location}
                            </span>
                          </div>
                        </div>
                      </div>

                      <div className="flex items-center justify-between border-t border-slate-100 pt-2.5 mt-3">
                        <div className="bg-[#ecfdf5] text-[#059669] border border-[#a7f3d0]/60 px-2.5 py-0.5 rounded-full flex items-center gap-1.5">
                          <div className="w-1.5 h-1.5 bg-[#10b981] rounded-full"></div>
                          <span className="text-[9.5px] font-extrabold">
                            {book.available} of {book.total} Copies Available
                          </span>
                        </div>

                        <button
                          onClick={() => setSelectedBook(book)}
                          className="bg-[#1e293b] text-white text-[10.5px] font-extrabold px-3 py-1 rounded-full flex items-center gap-1 hover:bg-[#0f172a] transition-colors cursor-pointer shadow-xs"
                        >
                          <span>View Copies</span>
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" className="w-2.5 h-2.5">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                          </svg>
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              {/* Server-side pagination: the catalog is not limited to one page. */}
              {pagination.last_page > 1 && (
                <div className="flex items-center justify-between gap-3 mt-4 bg-white rounded-[1.25rem] px-5 py-3 shadow-xs border border-slate-200/70">
                  <span className="text-[11.5px] font-semibold text-slate-500">
                    Page {pagination.current_page} of {pagination.last_page} · {pagination.total} titles
                  </span>
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => setPage(p => Math.max(1, p - 1))}
                      disabled={pagination.current_page <= 1}
                      className="px-3.5 py-1.5 rounded-lg border border-slate-200 text-[11.5px] font-extrabold text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                    >
                      Previous
                    </button>
                    <button
                      onClick={() => setPage(p => Math.min(pagination.last_page, p + 1))}
                      disabled={pagination.current_page >= pagination.last_page}
                      className="px-3.5 py-1.5 rounded-lg border border-slate-200 text-[11.5px] font-extrabold text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                    >
                      Next
                    </button>
                  </div>
                </div>
              )}
            </>
          )}

          {activeTab === 'borrowing' && canBorrow && (
            <div className="flex flex-col gap-4.5 w-full">
              {/* Top Full-Width Card: Your Active Book Loans */}
              <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 pb-1">
                  <div>
                    <div className="flex items-center gap-2">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.2} stroke="currentColor" className="w-5 h-5 text-[#8B1A24]">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                      </svg>
                      <h2 className="text-[16px] font-extrabold text-[#0f172a]">Your Active Book Loans ({activeLoanCount})</h2>
                    </div>
                    <p className="text-slate-400 text-[11px] font-medium mt-1">
                      Books currently checked out under your student profile ({user?.username || '2012-00000-SYS'}).
                    </p>
                  </div>
                  <div className="self-start sm:self-auto bg-slate-100 text-slate-700 text-[10px] font-extrabold px-3 py-1.5 rounded-lg shrink-0">
                    Max Allowed: {activeLoanCount} / {borrowLimit ?? '∞'} Books
                  </div>
                  {overdueCount > 0 && (
                    <div className="self-start sm:self-auto bg-rose-600 text-white text-[10px] font-extrabold px-3 py-1.5 rounded-lg shrink-0">
                      {overdueCount} Overdue
                    </div>
                  )}
                </div>

                {myLoans.length === 0 ? (
                  <div className="border border-slate-100 rounded-2xl py-12 px-6 flex flex-col items-center justify-center bg-slate-50/50 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-9 h-9 text-slate-300 mb-2.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                    </svg>
                    <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No active book loans at this time.</h3>
                    <p className="text-slate-400 text-[11px] font-medium">
                      Browse the catalog to find available physical books for research or coursework.
                    </p>
                  </div>
                ) : (
                  <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    {myLoans.map(loan => (
                      <div
                        key={loan.transaction_id}
                        className="border border-emerald-200 bg-emerald-50/30 rounded-xl p-4 shadow-xs cursor-pointer hover:border-emerald-300 transition-colors"
                        onClick={() => setSelectedLoan(loan)}
                      >
                        <h4 className="font-extrabold text-[#0f172a] text-[13px] mb-1 truncate" title={loan.book_copy?.book?.book_title}>
                          {loan.book_copy?.book?.book_title}
                        </h4>
                        <p className="text-[11px] text-slate-500 mb-2 font-medium">
                          Accession: <span className="font-extrabold text-[#0f172a]">{loan.book_copy?.accession_number || `CPY-${loan.book_copy?.copy_id}`}</span>
                        </p>
                        <div className="flex items-center justify-between mt-3 pt-3 border-t border-emerald-100/50">
                          <span className={`text-[10px] font-extrabold uppercase tracking-wider `}>
                            Due: {new Date(loan.due_date).toLocaleDateString()}
                          </span>
                          {loan.is_overdue && (
                            <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded bg-rose-600 text-white">
                              Overdue {loan.days_overdue}d
                            </span>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* Bottom 2-Column Grid: Fines Ledger & Book Hold Requests */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4.5">
                {/* Left: Library Fines & Clearance Ledger */}
                <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center justify-between gap-2 mb-4">
                      <div className="flex items-center gap-2">
                        <span className="text-[#8B1A24] font-black text-lg leading-none">$</span>
                        <h2 className="text-[15px] font-extrabold text-[#0f172a]">Library Fines & Clearance Ledger</h2>
                      </div>
                      <span className="bg-[#d1fae5] text-[#065f46] text-[9.5px] font-black uppercase px-2.5 py-1 rounded tracking-wider">
                        CLEAR ACCOUNT
                      </span>
                    </div>

                    <div className="border border-slate-100 rounded-xl p-4 bg-white shadow-2xs mb-3.5">
                      <div className="flex justify-between items-center mb-1.5">
                        <span className="text-[12px] font-extrabold text-slate-600">Unpaid Library Fines Balance:</span>
                        <span className="text-[17px] font-black text-[#8B1A24]">{peso(myBalance)}</span>
                      </div>
                      <p className="text-slate-400 text-[10.5px] font-medium leading-relaxed">
                        Calculated at {peso(dailyRate)} per overdue calendar day. Fines are charged when the librarian checks the book in, and must be cleared before borrowing again.
                      </p>
                    </div>
                  </div>

                  <div className="border border-sky-200 bg-sky-50/60 rounded-xl p-3.5 flex gap-2.5 items-start mt-auto">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 shrink-0 text-sky-600 mt-0.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                    <p className="text-[10.5px] text-sky-800 font-medium leading-relaxed">
                      Payments can be settled at the University Cashier (Ground Floor Main Admin) or through online GCash/Bank Deposit clearance verification.
                    </p>
                  </div>
                </div>

                {/* Right: Your Book Hold Requests */}
                <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center justify-between gap-2 mb-4">
                      <div className="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.2} stroke="currentColor" className="w-4 h-4 text-amber-500">
                          <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <h2 className="text-[15px] font-extrabold text-[#0f172a]">Your Book Hold Requests ({myHolds.length})</h2>
                      </div>
                      <span className="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">
                        Queue Status
                      </span>
                    </div>

                    {myHolds.length === 0 ? (
                      <div className="border border-slate-100 rounded-xl py-6 px-4 bg-white flex flex-col items-center justify-center text-center shadow-2xs mb-3.5">
                        <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No active book hold requests.</h3>
                        <p className="text-slate-400 text-[10.5px] font-medium">
                          When a book is out of stock, click "View Copies" to join the hold queue.
                        </p>
                      </div>
                    ) : (
                      <div className="flex flex-col gap-2.5 mb-3.5">
                        {myHolds.map(hold => (
                          <div key={hold.id} className="border border-slate-200/80 rounded-xl p-3.5 bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs">
                            <div>
                              <div className="flex items-center gap-2 mb-0.5">
                                <span className={`text-white text-[9px] font-black uppercase px-2 py-0.5 rounded leading-none ${hold.status === 'fulfilled' ? 'bg-emerald-600' : hold.status === 'pending_approval' ? 'bg-orange-500' : 'bg-[#8B1A24]'}`}>
                                  {hold.pos}
                                </span>
                                <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight">
                                  {hold.bookTitle}
                                </h3>
                              </div>
                              <p className="text-[10.5px] text-slate-400 font-medium">
                                Requested on {new Date(hold.date).toLocaleDateString()}
                              </p>
                            </div>
                            <button
                              onClick={() => handleCancelHold(hold.id)}
                              className="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[10.5px] font-extrabold py-1.5 px-3.5 rounded-xl transition-colors cursor-pointer self-end sm:self-auto shrink-0 shadow-xs"
                            >
                              Cancel Hold
                            </button>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>

                  <div className="border border-amber-200 bg-amber-50/60 rounded-xl p-3.5 flex gap-2.5 items-start mt-auto">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 shrink-0 text-amber-600 mt-0.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p className="text-[10.5px] text-amber-900 font-medium leading-relaxed">
                      Books held under "Ready for Pickup" are reserved at the Circulation Desk for 48 hours before proceeding to the next student in queue.
                    </p>
                  </div>
                </div>
              </div>

              {/* Borrowing history — active and returned loans, scoped to this user. */}
              <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70">
                <div className="flex items-center justify-between gap-2 mb-4 pb-2 border-b border-slate-100">
                  <div>
                    <h2 className="text-[15px] font-extrabold text-[#0f172a]">Your Borrowing History</h2>
                    <p className="text-slate-400 text-[11px] font-medium mt-0.5">
                      Every book you have borrowed, including returned copies.
                    </p>
                  </div>
                  <span className="bg-slate-100 text-slate-700 text-[10px] font-extrabold px-3 py-1.5 rounded-lg shrink-0">
                    {myHistory.length} record{myHistory.length === 1 ? '' : 's'}
                  </span>
                </div>

                {myHistory.length === 0 ? (
                  <p className="text-[11.5px] text-slate-500 italic py-6 text-center">
                    You have not borrowed anything yet.
                  </p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                      <thead>
                        <tr className="border-b border-slate-100">
                          <th className="py-2.5 px-3 text-[10px] font-black text-slate-400 uppercase tracking-wider">Title</th>
                          <th className="py-2.5 px-3 text-[10px] font-black text-slate-400 uppercase tracking-wider">Borrowed</th>
                          <th className="py-2.5 px-3 text-[10px] font-black text-slate-400 uppercase tracking-wider">Due</th>
                          <th className="py-2.5 px-3 text-[10px] font-black text-slate-400 uppercase tracking-wider">Returned</th>
                          <th className="py-2.5 px-3 text-[10px] font-black text-slate-400 uppercase tracking-wider">Status</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100/70">
                        {myHistory.map(t => (
                          <tr key={t.transaction_id} className="hover:bg-slate-50/50">
                            <td className="py-3 px-3 font-extrabold text-[12px] text-[#0f172a]">
                              {t.book_copy?.book?.book_title || '—'}
                            </td>
                            <td className="py-3 px-3 text-[11.5px] text-slate-600">
                              {t.date_borrowed ? new Date(t.date_borrowed).toLocaleDateString() : '—'}
                            </td>
                            <td className="py-3 px-3 text-[11.5px] text-slate-600">
                              {t.due_date ? new Date(t.due_date).toLocaleDateString() : '—'}
                            </td>
                            <td className="py-3 px-3 text-[11.5px] text-slate-600">
                              {t.actual_return_date ? new Date(t.actual_return_date).toLocaleDateString() : '—'}
                            </td>
                            <td className="py-3 px-3">
                              {t.status === 'active' && t.is_overdue ? (
                                <span className="text-[9.5px] font-black uppercase px-2 py-0.5 rounded bg-rose-600 text-white">
                                  Overdue {t.days_overdue}d
                                </span>
                              ) : t.status === 'active' ? (
                                <span className="text-[9.5px] font-black uppercase px-2 py-0.5 rounded bg-emerald-100 text-emerald-700">
                                  On Loan
                                </span>
                              ) : (
                                <span className="text-[9.5px] font-black uppercase px-2 py-0.5 rounded bg-slate-100 text-slate-600">
                                  Returned
                                </span>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          )}

          {activeTab === 'reserves' && (
            <div className="flex flex-col gap-4">
              {isFaculty ? (
                <TeacherReservesView books={books} onSectionsLoaded={handleFacultySections} />
              ) : (
                <>
                  <div className="bg-white rounded-[1.25rem] p-5 shadow-xs border border-slate-200/70 flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2 mb-1">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4 text-sky-600" aria-hidden="true">
                          <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
                        </svg>
                        <h2 className="text-[16px] font-extrabold text-[#0f172a]">Course Reserve Books &amp; Syllabus References</h2>
                      </div>
                      <p className="text-slate-400 text-[11px] font-medium">
                        Textbooks set aside by professors for 2-Hour In-Library Desk Reference or Overnight study.
                      </p>
                    </div>
                    <span className="bg-slate-100 text-slate-700 text-[10px] font-extrabold px-3 py-1.5 rounded-lg shrink-0">
                      {reserves.length} reserve{reserves.length === 1 ? '' : 's'}
                    </span>
                  </div>

                  {loading ? (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      {Array.from({ length: 2 }).map((_, i) => (
                        <div key={i} className="border border-slate-200 rounded-[1.25rem] p-4 animate-pulse">
                          <div className="h-3 bg-slate-200 rounded w-1/3 mb-3"></div>
                          <div className="h-4 bg-slate-200 rounded w-2/3 mb-2"></div>
                          <div className="h-2.5 bg-slate-100 rounded w-1/2"></div>
                        </div>
                      ))}
                    </div>
                  ) : reserves.length === 0 ? (
                    <div className="bg-white rounded-[1.25rem] border border-slate-200/70 shadow-xs py-12 px-6 flex flex-col items-center justify-center text-center">
                      <h3 className="font-extrabold text-[#0f172a] text-[14px] mb-1">No course reserves.</h3>
                      <p className="text-slate-400 text-[11.5px] font-medium max-w-sm">
                        {isLibrarian
                          ? 'Reserves appear here when a teacher requests one for a section.'
                          : 'Your professors have not set aside any titles for your sections yet.'}
                      </p>
                    </div>
                  ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      {reserves.map(reserve => (
                        isLibrarian ? (
                          <AdminReserveCard
                            key={reserve.id}
                            reserve={reserve}
                            onUpdateStatus={handleUpdateReserveStatus}
                            onAllocate={handleAllocateCopies}
                            busyKey={actionBusy}
                          />
                        ) : (
                          <StudentCourseReserveCard
                            key={reserve.id}
                            reserve={reserve}
                            onViewClassmates={handleViewClassmates}
                            onRequestBorrow={handleRequestReserveCopy}
                          />
                        )
                      ))}
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {/* Circulation Desk (Desktop) - Only on SuperAdmin and Admin */}
          {activeTab === 'circulation' && isLibrarian && (
            <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 pb-2 border-b border-slate-100">
                <div>
                  <h2 className="text-[18px] font-extrabold text-[#0f172a]">Circulation Log & Active Loans</h2>
                  <p className="text-slate-400 text-[11px] font-medium mt-0.5">
                    Track individual physical copies using unique ABC-LIB accession numbers.
                  </p>
                </div>
                <span className="self-start sm:self-auto bg-slate-100 text-slate-700 text-[9.5px] font-extrabold px-3 py-1.5 rounded-md uppercase tracking-wider">
                  Circulation Desk #01
                </span>
              </div>

              {/* Manual check-out, matching the mobile circulation desk. */}
              <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-4">
                <h3 className="font-extrabold text-[13px] text-[#0f172a] mb-3">Check-Out Book Copy</h3>
                <form
                  className="flex flex-col sm:flex-row gap-2.5"
                  onSubmit={(e) => {
                    handleCheckout(e, checkoutData.userId, checkoutData.copyId);
                    setCheckoutData({ userId: '', copyId: '' });
                  }}
                >
                  <input
                    type="text"
                    placeholder="Borrower User ID"
                    aria-label="Borrower User ID"
                    value={checkoutData.userId}
                    onChange={(e) => setCheckoutData({ ...checkoutData, userId: e.target.value })}
                    className="flex-1 px-3 py-2 bg-white border border-slate-200 rounded-lg text-[12.5px] focus:ring-1 focus:ring-[#8B1A24]"
                    required
                  />
                  <input
                    type="text"
                    placeholder="Book Copy ID"
                    aria-label="Book Copy ID"
                    value={checkoutData.copyId}
                    onChange={(e) => setCheckoutData({ ...checkoutData, copyId: e.target.value })}
                    className="flex-1 px-3 py-2 bg-white border border-slate-200 rounded-lg text-[12.5px] focus:ring-1 focus:ring-[#8B1A24]"
                    required
                  />
                  <button
                    type="submit"
                    disabled={actionBusy === 'checkout'}
                    className="bg-[#8B1A24] text-white text-[12px] font-extrabold py-2 px-5 rounded-lg hover:bg-[#6b141c] transition-colors cursor-pointer shadow-xs shrink-0 disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {actionBusy === 'checkout' ? <><Loader2 className="w-3.5 h-3.5 animate-spin mr-1.5 inline-block" />Checking out...</> : 'Check Out'}
                  </button>
                </form>
              </div>

              {/* Search + scope: active loans, or the full transaction history. */}
              <div className="mb-4 flex flex-col sm:flex-row gap-2.5">
                <input
                  type="text"
                  placeholder="Search by borrower ID, name, copy, or title…"
                  aria-label="Search circulation"
                  value={circulationSearch}
                  onChange={(e) => setCirculationSearch(e.target.value)}
                  className="flex-1 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-[13px] focus:ring-1 focus:ring-emerald-500"
                />
                <div className="flex rounded-xl border border-slate-200 overflow-hidden shrink-0">
                  {[['active', 'Active'], ['all', 'All History'], ['returned', 'Returned']].map(([value, label]) => (
                    <button
                      key={value}
                      onClick={() => setCirculationScope(value)}
                      aria-pressed={circulationScope === value}
                      className={`px-3.5 py-2.5 text-[11.5px] font-extrabold transition-colors cursor-pointer ${circulationScope === value
                          ? 'bg-[#1e293b] text-white'
                          : 'bg-white text-slate-600 hover:bg-slate-50'
                        }`}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </div>

              {loading ? (
                <div className="flex flex-col gap-2">
                  {Array.from({ length: 3 }).map((_, i) => (
                    <div key={i} className="h-12 bg-slate-100 rounded-xl animate-pulse"></div>
                  ))}
                </div>
              ) : filteredCirculation.length === 0 ? (
                <div className="border border-slate-100 rounded-2xl py-12 px-6 flex flex-col items-center justify-center bg-slate-50/50 text-center">
                  <h3 className="font-extrabold text-[#0f172a] text-[14px] mb-1">
                    {circulationSearch ? 'No matching transactions.' : circulationScope === 'active' ? 'No active loans right now.' : 'No transactions in this view.'}
                  </h3>
                  <p className="text-slate-400 text-[11.5px] font-medium max-w-sm">
                    {circulationSearch
                      ? 'Try a different borrower, copy or title.'
                      : 'Checked-out copies appear here until they are returned.'}
                  </p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left border-collapse">
                    <thead>
                      <tr className="border-b border-slate-100">
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">COPY</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">BOOK TITLE</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">BORROWER</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">DUE DATE</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">RETURNED</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">STATUS</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">ACTIONS</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100/70">
                      {filteredCirculation.map(item => (
                        <tr key={item.id} className="hover:bg-slate-50/50 transition-colors">
                          <td className="py-3.5 px-3 align-middle">
                            <div className="font-mono text-[12px] font-extrabold text-[#0f172a]">{item.copyId}</div>
                            <div className="font-mono text-[9.5px] text-slate-400 mt-0.5">{item.accession || `CPY-${item.copyId}`}</div>
                          </td>
                          <td className="py-3.5 px-3 align-middle">
                            <div className="font-extrabold text-[12.5px] text-[#0f172a] leading-tight">{item.title}</div>
                            <div className="text-[10.5px] text-slate-400 font-medium mt-0.5">{item.author}</div>
                          </td>
                          <td className="py-3.5 px-3 align-middle">
                            <div className="font-extrabold text-[12.5px] text-[#0f172a] leading-tight">{item.borrower}</div>
                            <div className="text-[10px] text-slate-400 font-medium mt-0.5">ID: {item.borrowerId}</div>
                          </td>
                          <td className="py-3.5 px-3 align-middle">
                            <span className={`font-extrabold text-[11px] ${item.isOverdue && item.status !== 'returned' ? 'text-rose-600' : 'text-slate-500'}`}>
                              {item.dueDate ? new Date(item.dueDate).toLocaleDateString() : '—'}
                            </span>
                          </td>
                          <td className="py-3.5 px-3 align-middle">
                            <span className="text-[11px] font-bold text-slate-500">
                              {item.returnedAt ? new Date(item.returnedAt).toLocaleDateString() : '—'}
                            </span>
                          </td>
                          <td className="py-3.5 px-3 align-middle">
                            <StatusBadge status={item.status === 'returned' ? 'returned' : item.isOverdue ? 'overdue' : 'checked out'} />
                          </td>
                          <td className="py-3.5 px-3 align-middle">
                            {item.status !== 'returned' && (
                              <button
                                onClick={(e) => handleCheckin(e, item.id)}
                                disabled={actionBusy === `checkin-${item.id}`}
                                className="bg-[#047857] hover:bg-[#065f46] text-white text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer shadow-xs whitespace-nowrap disabled:opacity-60 disabled:cursor-not-allowed"
                              >
                                {actionBusy === `checkin-${item.id}` ? <><Loader2 className="w-3 h-3 animate-spin mr-1.5 inline-block" />Checking in...</> : 'Check-In Copy'}
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}

          {/* Admin Fines & Queue (Desktop) - Only on SuperAdmin and Admin */}
          {activeTab === 'fines' && isLibrarian && (
            <div className="flex flex-col gap-4 mt-2">
              <RenewalRequestsPanel
                requests={pendingRenewals}
                onDecide={handleDecideRenewal}
                busyKey={actionBusy}
                loading={loading || panelLoading}
              />

              <HoldQueuePanel
                requests={filteredHoldRequests}
                totalRequests={holdRequests.length}
                bookOptions={[...new Set(holdRequests.map(r => r.bookTitle))]}
                statusFilter={holdStatusFilter}
                setStatusFilter={setHoldStatusFilter}
                bookFilter={holdBookFilter}
                setBookFilter={setHoldBookFilter}
                search={holdSearchQuery}
                setSearch={setHoldSearchQuery}
                onAccept={handleAcceptHold}
                onCheckout={handleCheckout}
                onCancel={handleCancelHold}
                loading={loading || panelLoading}
              />

              <AdminFinesPanel finesData={finesData} onSettled={refreshCurrent} loading={loading || panelLoading} />
            </div>
          )}

          {/* Add Title & Physical Copies Tab (Desktop) - Only on SuperAdmin and Admin */}
          {activeTab === 'add_title' && isLibrarian && (
            <>
              <div className="bg-slate-50 p-1.5 rounded-xl border border-slate-200 flex flex-wrap gap-1 mb-4 shadow-xs">
                {[
                  ['inventory', 'Inventory', Package],
                  ['settings', 'Settings', Settings],
                ].map(([value, label, Icon]) => (
                  <button
                    key={value}
                    onClick={() => setManageSection(value)}
                    className={`flex-1 min-w-[8rem] py-2.5 px-4 rounded-lg text-[13px] font-extrabold transition-all cursor-pointer flex items-center justify-center gap-2 ${
                      manageSection === value
                      ? 'bg-white text-slate-800 shadow-sm border border-slate-200'
                      : 'bg-transparent text-slate-500 hover:bg-slate-100 hover:text-slate-700 border border-transparent'
                      }`}
                  >
                    <Icon className="w-4 h-4" />
                    {label}
                  </button>
                ))}
              </div>

              {manageSection === 'settings' && <LibrarySettingsPanel />}

              {manageSection === 'inventory' && (
                <>
              <AdminInventoryPanel books={books} onChanged={refreshCurrent} />
              <div className="h-4" />
              <div className="max-w-2xl mx-auto w-full bg-white rounded-[1.25rem] p-6 sm:p-8 shadow-xs border border-slate-200/70">
                <div className="mb-6 pb-4 border-b border-slate-100 text-center">
                  <span className="inline-block bg-slate-100 text-slate-600 border border-slate-200 text-[9.5px] font-black uppercase px-2.5 py-1 rounded tracking-wider mb-2">
                    LIBRARY INVENTORY
                  </span>
                  <h2 className="text-[20px] font-extrabold text-[#0f172a] leading-tight">
                    Register New Title
                  </h2>
                  <p className="text-slate-400 text-[11.5px] font-medium mt-1">
                    Enter complete book bibliographic details and initial physical copy inventory.
                  </p>
                </div>

                <AddBookForm 
                  newBook={newBook} 
                  setNewBook={setNewBook} 
                  handleAddBook={handleAddBook} 
                  onCancel={() => setActiveTab('catalog')} 
                  actionBusy={actionBusy}
                categories={categories}
                onCategoriesChanged={registerCategory}
                canManageCategories={isLibrarian}
                />
              </div>
                </>
              )}
            </>
          )}
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 📋 SHARED MODALS: View Copies, Request Course Reserve                      */}
      {/* ========================================================================= */}

      {/* Modal: View Copies & Physical RFID Status */}
      {selectedBook && (
        <div className="fixed inset-0 flex items-center justify-center p-4 z-50">
          <div
            className="absolute inset-0 bg-black/50 backdrop-blur-xs anim-fade-in"
            onClick={() => setSelectedBook(null)}
            aria-hidden="true"
          />
          <div
            ref={bookModalRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="book-modal-title"
            className="relative z-10 bg-white rounded-[1.5rem] p-5 lg:p-6 shadow-2xl border border-slate-100 max-w-md w-full anim-zoom-in"
          >
            <div className="flex justify-between items-start mb-3">
              <div>
                <span className="inline-block bg-sky-50 text-sky-700 text-[8.5px] font-black uppercase px-2 py-0.5 rounded tracking-wider mb-1">
                  PHYSICAL COPY INVENTORY
                </span>
                <h2 id="book-modal-title" className="text-[16px] font-extrabold text-[#0f172a] leading-tight">
                  {selectedBook.title}
                </h2>
                <p className="text-slate-400 text-[11px] font-medium mt-0.5">by {selectedBook.author}</p>
              </div>
              <button
                onClick={() => setSelectedBook(null)}
                aria-label="Close copy inventory"
                className="text-slate-400 hover:text-slate-600 text-lg font-bold p-1 cursor-pointer"
              >
                ✕
              </button>
            </div>

            <div className="bg-slate-50 rounded-xl p-3 mb-3 text-[11px] font-medium text-slate-600 flex justify-between items-center border border-slate-100">
              <span>Shelf: <strong className="text-[#0f172a]">{selectedBook.location}</strong></span>
              <span className="bg-emerald-100 text-emerald-800 text-[9.5px] font-black px-2 py-0.5 rounded-full">
                {selectedBook.available} / {selectedBook.total} Available
              </span>
            </div>

            <div className="flex flex-col gap-2 mb-4 max-h-48 overflow-y-auto pr-1">
              {selectedBook.copies && selectedBook.copies.map((copy) => {
                const labels = {
                  available: 'Available on Shelf',
                  on_hold: 'Held for Pickup',
                  checked_out: 'Already Borrowed',
                };

                return (
                  <div key={copy.copy_id} className="border border-slate-200 rounded-xl p-2.5 flex items-center justify-between gap-2 bg-white shadow-2xs">
                    <div className="min-w-0">
                      <div className="font-mono text-[11px] font-extrabold text-[#0f172a]">{copy.accession_number || `CPY-${copy.copy_id}`}</div>
                      <div className="text-[9px] text-slate-400 font-medium">Condition: {copy.condition}</div>
                    </div>
                    <StatusBadge
                      status={copy.availability_status === 'on_hold' ? 'on hold' : copy.availability_status}
                      label={labels[copy.availability_status]}
                      className="shrink-0"
                    />
                  </div>
                );
              })}
              {(!selectedBook.copies || selectedBook.copies.length === 0) && (
                <div className="text-center text-sm text-slate-500 py-4">No physical copies found.</div>
              )}
            </div>

            <div className="flex justify-end gap-2.5 pt-2.5 border-t border-slate-100">
              <button
                onClick={() => setSelectedBook(null)}
                className="px-4 py-2 border border-slate-200 rounded-xl text-slate-700 text-[11px] font-extrabold hover:bg-slate-50 cursor-pointer"
              >
                Close
              </button>
              <button
                onClick={() => handleHoldRequest(selectedBook.id)}
                disabled={actionBusy === `hold-${selectedBook.id}`}
                className="px-4 py-2 bg-[#8B1A24] text-white rounded-xl text-[11px] font-extrabold hover:bg-[#6b141c] transition-colors shadow-xs cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
              >
                {actionBusy === `hold-${selectedBook.id}`
                  ? 'Submitting…'
                  : selectedBook.available > 0 ? 'Borrow / Reserve Book' : 'Join Waitlist'}
              </button>
            </div>
          </div>
        </div>
      )}


      {/* Modal: Active Loan Details */}
      {selectedLoan && (
        <div className="fixed inset-0 flex items-center justify-center p-4 z-50">
          <div
            className="absolute inset-0 bg-black/60 backdrop-blur-xs anim-fade-in"
            onClick={() => setSelectedLoan(null)}
            aria-hidden="true"
          />
          <div
            ref={loanModalRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="loan-modal-title"
            className="relative z-10 bg-white rounded-[1.5rem] p-6 shadow-2xl border border-slate-100 max-w-md w-full anim-zoom-in"
          >
            <div className="flex justify-between items-start mb-4">
              <div>
                <span className="inline-block bg-[#e0f2fe] text-[#0369a1] border border-[#bae6fd] text-[9.5px] font-black uppercase px-2.5 py-0.5 rounded tracking-wider mb-1">
                  LOAN DETAILS
                </span>
                <h2 id="loan-modal-title" className="text-[19px] font-black text-[#0f172a] leading-tight tracking-tight pr-4">
                  {selectedLoan.book_copy?.book?.book_title}
                </h2>
                <p className="text-slate-400 text-[11px] font-medium mt-0.5">by {selectedLoan.book_copy?.book?.author}</p>
              </div>
              <button
                onClick={() => setSelectedLoan(null)}
                aria-label="Close loan details"
                className="text-slate-400 hover:text-slate-600 text-lg font-bold p-1 cursor-pointer transition-colors"
              >
                ✕
              </button>
            </div>

            <div className="flex flex-col gap-3">
              <div className="flex justify-between items-center py-2 border-b border-slate-100">
                <span className="text-[11px] font-extrabold text-slate-500 uppercase">Accession No.</span>
                <span className="text-[12px] font-black text-[#0f172a] bg-slate-100 px-2 py-0.5 rounded">
                  {selectedLoan.book_copy?.accession_number || `CPY-${selectedLoan.book_copy?.copy_id}`}
                </span>
              </div>
              <div className="flex justify-between items-center py-2 border-b border-slate-100">
                <span className="text-[11px] font-extrabold text-slate-500 uppercase">Borrow Date</span>
                <span className="text-[12px] font-bold text-[#0f172a]">
                  {selectedLoan.date_borrowed ? new Date(selectedLoan.date_borrowed).toLocaleDateString() : '—'}
                </span>
              </div>
              <div className="flex justify-between items-center py-2 border-b border-slate-100">
                <span className="text-[11px] font-extrabold text-slate-500 uppercase">Due Date</span>
                <span className={`text-[12px] font-black ${selectedLoan.is_overdue ? 'text-[#8B1A24]' : 'text-[#0f172a]'}`}>
                  {new Date(selectedLoan.due_date).toLocaleDateString()}
                </span>
              </div>
              <div className="flex justify-between items-center py-2 border-b border-slate-100">
                <span className="text-[11px] font-extrabold text-slate-500 uppercase">Physical Condition</span>
                <span className="text-[12px] font-bold text-[#0f172a] capitalize">
                  {selectedLoan.book_copy?.condition || '—'}
                </span>
              </div>
            </div>

            <div className="mt-5 p-3.5 bg-amber-50 border border-amber-200/60 rounded-xl">
              <div className="flex gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-amber-600 shrink-0 mt-0.5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                </svg>
                <p className="text-[11px] font-medium text-amber-800 leading-relaxed">
                  <strong>Return Instructions:</strong> Return this book to the Circulation Desk on or before the due date to avoid late fees of {peso(dailyRate)}/day.
                </p>
              </div>
            </div>

            {selectedLoan.is_overdue && (
              <div className="mt-3 p-3.5 bg-rose-50 border border-rose-200 rounded-xl">
                <p className="text-[11px] font-bold text-rose-800 leading-relaxed">
                  Overdue by {selectedLoan.days_overdue} day{selectedLoan.days_overdue === 1 ? '' : 's'}.
                  Estimated fine if returned today: {peso(selectedLoan.estimated_fine)}.
                  <span className="block font-medium mt-0.5">The fine is charged when the librarian checks the book in.</span>
                </p>
              </div>
            )}

            <div className="flex justify-end gap-2.5 mt-5">
              <button
                onClick={() => setSelectedLoan(null)}
                className="px-4 py-2 border border-slate-200 rounded-xl text-xs font-extrabold hover:bg-slate-50 cursor-pointer"
              >
                Close
              </button>
              {selectedLoan.status === 'active' && (
                selectedLoan.renewal_status === 'pending' ? (
                  <span className="px-4 py-2 rounded-xl text-xs font-extrabold bg-amber-50 text-amber-700 border border-amber-200">
                    Renewal Requested
                  </span>
                ) : (
                  <button
                    onClick={() => handleRequestRenewal(selectedLoan.transaction_id)}
                    disabled={actionBusy === `renew-${selectedLoan.transaction_id}`}
                    className="px-4 py-2 bg-[#8B1A24] hover:bg-[#6b141c] text-white rounded-xl text-xs font-extrabold cursor-pointer shadow-xs disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {actionBusy === `renew-${selectedLoan.transaction_id}` ? <><Loader2 className="w-3 h-3 animate-spin mr-1.5 inline-block" />Requesting...</> : 'Request Renewal'}
                  </button>
                )
              )}
            </div>
          </div>
        </div>
      )}

      {/* Modal: Register New Title & Physical Copies Pop-Up */}
      {showAddBookModal && (
        <div className="fixed inset-0 flex items-center justify-center p-4 z-50">
          <div
            className="absolute inset-0 bg-black/60 backdrop-blur-xs anim-fade-in"
            onClick={() => setShowAddBookModal(false)}
            aria-hidden="true"
          />
          <div
            ref={addBookModalRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="add-book-modal-title"
            className="relative z-10 bg-white rounded-[1.5rem] p-6 shadow-2xl border border-slate-100 max-w-lg w-full max-h-[92vh] overflow-y-auto anim-zoom-in"
          >
            <div className="flex justify-between items-start mb-2">
              <div>
                <span className="inline-block bg-[#fef3c7] text-[#92400e] border border-[#fde68a] text-[9.5px] font-black uppercase px-2.5 py-0.5 rounded tracking-wider mb-1">
                  LIBRARY INVENTORY MANAGEMENT
                </span>
                <h2 id="add-book-modal-title" className="text-[19px] font-black text-[#0f172a] leading-tight tracking-tight">
                  Register New Title &amp; Physical Copies
                </h2>
              </div>
              <button
                type="button"
                onClick={() => setShowAddBookModal(false)}
                aria-label="Close new title form"
                className="text-slate-400 hover:text-slate-600 text-lg font-bold p-1 cursor-pointer transition-colors"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleAddBook} className="flex flex-col gap-3.5 mt-3">
              <div>
                <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Book Title *</label>
                <input
                  type="text"
                  required
                  value={newBook.title}
                  onChange={(e) => setNewBook({ ...newBook, title: e.target.value })}
                  placeholder="e.g. Operating System Concepts 10th Ed."
                  className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Author *</label>
                  <input
                    type="text"
                    required
                    value={newBook.author}
                    onChange={(e) => setNewBook({ ...newBook, author: e.target.value })}
                    placeholder="e.g. Abraham Silberschatz"
                    className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                  />
                </div>

                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">ISBN-13 *</label>
                  <input
                    type="text"
                    required
                    value={newBook.isbn}
                    onChange={(e) => setNewBook({ ...newBook, isbn: e.target.value })}
                    placeholder="e.g. 978-1118063330"
                    className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label htmlFor="add-book-modal-category" className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Category</label>
                  <CategorySelect
                    id="add-book-modal-category"
                    value={newBook.category}
                    onChange={(next) => setNewBook({ ...newBook, category: next })}
                    categories={categories}
                    onCategoriesChanged={registerCategory}
                    canCreate={isLibrarian}
                    required
                    className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] font-bold bg-[#f8fafc] cursor-pointer"
                  />
                </div>

                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Copy Quantity</label>
                  <input
                    type="number"
                    min="1"
                    max="50"
                    value={newBook.copies}
                    onChange={(e) => setNewBook({ ...newBook, copies: e.target.value })}
                    className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] font-extrabold bg-[#f8fafc]"
                  />
                </div>
              </div>

              <div>
                <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Shelf Location</label>
                <input
                  type="text"
                  value={newBook.location}
                  onChange={(e) => setNewBook({ ...newBook, location: e.target.value })}
                  placeholder="Floor 2 - Shelf CS-101"
                  className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                />
              </div>

              <div className="flex justify-end items-center gap-2.5 pt-3 border-t border-slate-100 mt-1">
                <button
                  type="button"
                  onClick={() => setShowAddBookModal(false)}
                  className="px-4 py-2 border border-slate-200 rounded-xl text-slate-700 text-[11.5px] font-extrabold hover:bg-slate-50 cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={actionBusy === 'add-book'}
                  className="px-4 py-2 bg-[#8B1A24] text-white rounded-xl text-[11.5px] font-extrabold hover:bg-[#6b141c] transition-colors shadow-xs cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                >
                  {actionBusy === 'add-book' ? <><Loader2 className="w-3.5 h-3.5 animate-spin mr-1.5 inline-block" />Saving...</> : 'Save Title & Register Copies'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Classmates.

          There is no classmates endpoint yet, so no request is made and no
          names are invented: the modal shows its own "unavailable" state until
          the roster is served. */}
      <ClassmatesModal
        open={!!classmatesFor}
        sectionName={classmatesFor?.course}
        classmates={classmates}
        onClose={() => { setClassmatesFor(null); setClassmates(undefined); }}
      />

      {/* Background refresh. Kept out of the layout flow so the page does not
          jump back to skeletons after every action. */}
      {refreshing && (
        <div
          role="status"
          aria-live="polite"
          className="fixed bottom-4 left-4 z-40 bg-white/95 border border-slate-200 shadow-lg rounded-full px-3.5 py-1.5 text-[11px] font-extrabold text-slate-600 flex items-center gap-2 anim-fade-in"
        >
          <span className="w-2 h-2 rounded-full bg-[#8B1A24] animate-pulse" aria-hidden="true"></span>
          Updating…
        </div>
      )}
    </div>
  );
}


