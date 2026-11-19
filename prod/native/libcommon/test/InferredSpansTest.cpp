#include "InferredSpans.h"

#include <gtest/gtest.h>
#include <new>
#include <optional>

using namespace std::chrono_literals;

namespace opentelemetry::php {

namespace {

struct Counters {
    int interrupts = 0;
    int attaches = 0;
};

InferredSpans makeInferredSpans(Counters &c) {
    return InferredSpans([&c]() { ++c.interrupts; }, [&c](InferredSpans::time_point_t, InferredSpans::time_point_t) { ++c.attaches; });
}

void requestInterrupt(InferredSpans &is) {
    is.setInterval(1ms);
    is.tryRequestInterrupt(InferredSpans::time_point_t{std::chrono::milliseconds{1'000'000'000}});
}

} // namespace

TEST(InferredSpansTest, AttachesWhenInterrupted) {
    Counters c;
    auto is = makeInferredSpans(c);
    requestInterrupt(is);
    ASSERT_EQ(c.interrupts, 1);

    is.attachBacktraceIfInterrupted();
    EXPECT_EQ(c.attaches, 1);
}

TEST(InferredSpansTest, NoAttachWithoutInterrupt) {
    Counters c;
    auto is = makeInferredSpans(c);
    is.attachBacktraceIfInterrupted();
    EXPECT_EQ(c.attaches, 0);
}

TEST(InferredSpansTest, SuspensionBlocksAttachAndKeepsRequestPending) {
    Counters c;
    auto is = makeInferredSpans(c);
    requestInterrupt(is);

    {
        InferredSpans::SamplingSuspension suspension(is);
        is.attachBacktraceIfInterrupted();
        EXPECT_EQ(c.attaches, 0);
    }

    is.attachBacktraceIfInterrupted();
    EXPECT_EQ(c.attaches, 1);
}

TEST(InferredSpansTest, NestedSuspensionsResumeOnlyAfterLastRelease) {
    Counters c;
    auto is = makeInferredSpans(c);
    requestInterrupt(is);

    {
        InferredSpans::SamplingSuspension outer(is);
        {
            InferredSpans::SamplingSuspension inner(is);
            is.attachBacktraceIfInterrupted();
        }
        is.attachBacktraceIfInterrupted();
        EXPECT_EQ(c.attaches, 0);
    }

    is.attachBacktraceIfInterrupted();
    EXPECT_EQ(c.attaches, 1);
}

TEST(InferredSpansTest, MovedSuspensionReleasesOnce) {
    Counters c;
    auto is = makeInferredSpans(c);
    requestInterrupt(is);

    {
        std::optional<InferredSpans::SamplingSuspension> a{std::in_place, is};
        std::optional<InferredSpans::SamplingSuspension> b{std::move(a)};
        a.reset();
        is.attachBacktraceIfInterrupted();
        EXPECT_EQ(c.attaches, 0); // still held by b
    }

    is.attachBacktraceIfInterrupted();
    EXPECT_EQ(c.attaches, 1);
}

TEST(InferredSpansTest, InterruptIsRequestedAgainWhenSuspensionEnds) {
    Counters c;
    auto is = makeInferredSpans(c);
    requestInterrupt(is);
    ASSERT_EQ(c.interrupts, 1);

    {
        InferredSpans::SamplingSuspension suspension(is);
        is.attachBacktraceIfInterrupted(); // skipped, the pending request stays
    }
    EXPECT_EQ(c.interrupts, 2);
}

TEST(InferredSpansTest, NoInterruptWhenNothingWasPendingAtSuspensionEnd) {
    Counters c;
    auto is = makeInferredSpans(c);
    {
        InferredSpans::SamplingSuspension suspension(is);
    }
    EXPECT_EQ(c.interrupts, 0);
}

TEST(InferredSpansTest, ResetClearsSuspensionLeftByBailout) {
    Counters c;
    auto is = makeInferredSpans(c);
    requestInterrupt(is);

    // destructor never runs, like when a bailout longjmps over it
    alignas(InferredSpans::SamplingSuspension) unsigned char storage[sizeof(InferredSpans::SamplingSuspension)];
    new (storage) InferredSpans::SamplingSuspension(is);

    is.attachBacktraceIfInterrupted();
    EXPECT_EQ(c.attaches, 0);

    is.reset();
    is.attachBacktraceIfInterrupted();
    EXPECT_EQ(c.attaches, 1);
}

} // namespace opentelemetry::php
