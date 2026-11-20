#pragma once

#include <atomic>
#include <chrono>
#include <functional>
#include <mutex>
#include <utility>
#include <unistd.h>
namespace opentelemetry::php {

class InferredSpans {
public:
    using clock_t = std::chrono::steady_clock;
    using time_point_t = std::chrono::time_point<clock_t, std::chrono::milliseconds>;
    using interruptFunc_t = std::function<void()>;
    using attachInferredSpansOnPhp_t = std::function<void(time_point_t interruptRequest, time_point_t now)>;

    InferredSpans(interruptFunc_t interrupt, attachInferredSpansOnPhp_t attachInferredSpansOnPhp) : interrupt_(interrupt), attachInferredSpansOnPhp_(attachInferredSpansOnPhp) {
    }

    /// RAII guard: no backtrace is attached while it is alive (the interrupt request stays pending until after it)
    class SamplingSuspension {
    public:
        explicit SamplingSuspension(InferredSpans &owner) : owner_(&owner) {
            ++owner_->suspendCount_;
        }
        SamplingSuspension(SamplingSuspension &&other) noexcept : owner_(std::exchange(other.owner_, nullptr)) {
        }
        ~SamplingSuspension() {
            if (owner_) {
                owner_->resumeSampling();
            }
        }
        SamplingSuspension(SamplingSuspension const &) = delete;
        SamplingSuspension &operator=(SamplingSuspension const &) = delete;
        SamplingSuspension &operator=(SamplingSuspension &&) = delete;

    private:
        InferredSpans *owner_;
    };

    void attachBacktraceIfInterrupted() {
        if (suspendCount_.load() > 0) {
            return;
        }

        if (phpSideBacktracePending_.load()) { // avoid triggers from agent side with low interval
            return;
        }

        std::unique_lock lock(mutex_);
        time_point_t requestInterruptTime = lastInterruptRequestTick_;

        if (checkAndResetInterruptFlag()) {
            phpSideBacktracePending_ = true;
            lock.unlock();
            attachInferredSpansOnPhp_(requestInterruptTime, std::chrono::time_point_cast<std::chrono::milliseconds>(clock_t::now()));
            phpSideBacktracePending_ = false;
        }
    }

    void tryRequestInterrupt(time_point_t now) {
        if (interruptedRequested_.load()) {
            return; // it was requested to interrupt in previous interval
        }

        std::unique_lock lock(mutex_);

        if (now > lastInterruptRequestTick_ + samplingInterval_) {
            lastInterruptRequestTick_ = now;

            interruptedRequested_ = true;
            lock.unlock();
            interrupt_(); // set interrupt for user space functions
        }
    }

    void setInterval(std::chrono::milliseconds interval) {
        std::lock_guard lock(mutex_);
        samplingInterval_ = interval;
    }

    void reset() {
        suspendCount_ = 0; // a suspension is not released if a bailout skipped its destructor
        std::lock_guard lock(mutex_);
        lastInterruptRequestTick_ = std::chrono::time_point_cast<time_point_t::duration>(clock_t::now());
    }

private:
    void resumeSampling() {
        if (--suspendCount_ == 0 && interruptedRequested_.load()) {
            interrupt_(); // an interrupt that fired while suspended was skipped, ask for it again
        }
    }

    bool checkAndResetInterruptFlag() {
        bool interrupted = true;
        return interruptedRequested_.compare_exchange_strong(interrupted, false, std::memory_order_release, std::memory_order_acquire);
    }

    std::atomic_bool interruptedRequested_ = false;
    std::chrono::milliseconds samplingInterval_ = std::chrono::milliseconds(20);
    time_point_t lastInterruptRequestTick_ = std::chrono::time_point_cast<time_point_t::duration>(clock_t::now());
    std::mutex mutex_;
    interruptFunc_t interrupt_;
    attachInferredSpansOnPhp_t attachInferredSpansOnPhp_;
    std::atomic_bool phpSideBacktracePending_;
    std::atomic_int suspendCount_ = 0;
};

} // namespace opentelemetry::php