#pragma once

#include "LoggerInterface.h"
#include "LoggerSinkInterface.h"
#include "LogFeature.h"
#include "CommonUtils.h"
#include "SpinLock.h"

#include <atomic>
#include <memory>
#include <string>
#include <vector>


namespace opentelemetry::php {

class LoggerSinkFile : public LoggerSinkInterface {
public:
    LogLevel getLevel() const override;
    void setLevel(LogLevel) override;
    void writeLog(std::string const &formattedOutput, std::string_view message, std::string_view time, std::string_view level, std::string_view process) const override;
    bool reopen(std::string fileName);

private:
    std::atomic<LogLevel> level_ = LogLevel::logLevel_off;
    int fd_ = -1;
    std::string openedFilePath_;
    SpinLock spinLock_;
};

class LoggerSinkStdErr : public LoggerSinkInterface {
public:
    LogLevel getLevel() const override;
    void setLevel(LogLevel) override;
    void writeLog(std::string const &formattedOutput, std::string_view message, std::string_view time, std::string_view level, std::string_view process) const override;
private:
    std::atomic<LogLevel> level_ = LogLevel::logLevel_off;
};

class LoggerSinkSysLog : public LoggerSinkInterface {
public:
    LogLevel getLevel() const override;
    void setLevel(LogLevel) override;
    void writeLog(std::string const &formattedOutput, std::string_view message, std::string_view time, std::string_view level, std::string_view process) const override;
private:
    std::atomic<LogLevel> level_ = LogLevel::logLevel_warning;
};

class Logger : public LoggerInterface {
public:
    Logger(std::vector<std::shared_ptr<LoggerSinkInterface>> sinks) : sinks_(std::move(sinks)) {
    }

    void printf(LogLevel level, const char *format, ...) const override;
    void log(LogLevel level, const std::string &message) const override;

    bool doesMeetsLevelCondition(LogLevel level) const override;
    bool doesFeatureMeetsLevelCondition(LogLevel level, LogFeature feature) const override;

    void attachSink(std::shared_ptr<LoggerSinkInterface> sink);

    LogLevel getMaxLogLevel() const override;

    void setLogFeatures(std::unordered_map<opentelemetry::php::LogFeature, LogLevel> features) override;

private:
    std::string getFormattedTime() const;
    std::string getFormattedProcessData() const;
    std::vector<std::shared_ptr<LoggerSinkInterface>> sinks_;
    std::unordered_map<opentelemetry::php::LogFeature, LogLevel> features_;
    mutable SpinLock spinLock_;
};
}
